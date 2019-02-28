<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\records;

use Craft;
use craft\base\Element;
use craft\helpers\ArrayHelper;
use lhs\elasticsearch\Elasticsearch;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\elasticsearch\ActiveRecord;
use yii\helpers\Json;
use yii\helpers\VarDumper;

/**
 * @property string $title
 * @property string $url
 * @property mixed $section
 * @property object|array $content
 */
class ElasticsearchRecord extends ActiveRecord
{
    public static $siteId;

    private $_schema;
    private $_attributes = ['title', 'url', 'section', 'content'];
    private $_element;

    CONST EVENT_BEFORE_CREATE_INDEX = 'beforeCreateIndex';
    CONST EVENT_BEFORE_SAVE = 'beforeSave';

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        return $this->_attributes;
    }

    /**
     * Return an array of Elasticsearch records for the given query
     * @param string $query
     * @return ElasticsearchRecord[]
     * @throws InvalidConfigException
     * @throws \yii\elasticsearch\Exception
     */
    public static function search(string $query): array
    {
        $queryParams = [
            'multi_match' => [
                'fields'   => ['attachment.content', 'title'],
                'query'    => $query,
                'analyzer' => self::siteAnalyzer(),
                'operator' => 'and',
            ],
        ];

        $highlightParams = ArrayHelper::merge(Elasticsearch::getInstance()->settings->highlight, [
            'fields' => [
                'title'              => (object)['type' => 'plain'],
                'attachment.content' => (object)[],
            ],
        ]);
        return self::find()->query($queryParams)->highlight($highlightParams)->limit(self::find()->count())->all();
    }

    /**
     * Try to guess the best Elasticsearch analyze for the current site language
     * @return string
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function siteAnalyzer(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }

        $analyzer = 'standard'; // Default analyzer
        $availableAnalyzers = [
            'ar'    => 'arabic',
            'hy'    => 'armenian',
            'eu'    => 'basque',
            'bn'    => 'bengali',
            'pt-BR' => 'brazilian',
            'bg'    => 'bulgarian',
            'ca'    => 'catalan',
            'cs'    => 'czech',
            'da'    => 'danish',
            'nl'    => 'dutch',
            'pl'    => 'stempel', // analysis-stempel plugin needed
            'en'    => 'english',
            'fi'    => 'finnish',
            'fr'    => 'french',
            'gl'    => 'galician',
            'de'    => 'german',
            'el'    => 'greek',
            'hi'    => 'hindi',
            'hu'    => 'hungarian',
            'id'    => 'indonesian',
            'ga'    => 'irish',
            'it'    => 'italian',
            'ja'    => 'cjk',
            'ko'    => 'cjk',
            'lv'    => 'latvian',
            'lt'    => 'lithuanian',
            'nb'    => 'norwegian',
            'fa'    => 'persian',
            'pt'    => 'portuguese',
            'ro'    => 'romanian',
            'ru'    => 'russian',
            //sorani, Kurdish language is not part of the Craft locals...
            // 'sk' no analyzer available at this time
            'es'    => 'spanish',
            'sv'    => 'swedish',
            'tr'    => 'turkish',
            'th'    => 'thai',
            'zh'    => 'cjk' //Chinese
        ];

        $siteLanguage = Craft::$app->getSites()->getSiteById(static::$siteId)->language;
        if (array_key_exists($siteLanguage, $availableAnalyzers)) {
            $analyzer = $availableAnalyzers[$siteLanguage];
        } else {
            $localParts = explode('-', Craft::$app->language);
            $siteLanguage = $localParts[0];
            if (array_key_exists($siteLanguage, $availableAnalyzers)) {
                $analyzer = $availableAnalyzers[$siteLanguage];
            }
        }

        return $analyzer;
    }

    /**
     * @param bool $runValidation
     * @param null $attributeNames
     * @return bool
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function save($runValidation = true, $attributeNames = null): bool
    {
        if (!self::indexExists()) {
            $this->createESIndex();
        }
        $this->trigger(self::EVENT_BEFORE_SAVE, new Event());
        if (!$this->getIsNewRecord()) {
            $this->delete(); // pipeline in not supported by Document Update API :(
        }
        return $this->insert($runValidation, $attributeNames, ['pipeline' => 'attachment']);
    }

    public function createESIndex()
    {
        $this->setSchema([
            'mappings' => static::mapping(),
        ]);
        $this->trigger(self::EVENT_BEFORE_CREATE_INDEX, new Event());
        Craft::debug('Before create event - site: ' . self::$siteId . ' schema: ' . VarDumper::dumpAsString($this->getSchema()), __METHOD__);
        self::createIndex($this->getSchema());
    }

    /**
     * Return if the Elasticsearch index already exists or not
     * @return bool
     * @throws InvalidConfigException If the `$siteId` isn't set*
     */
    protected static function indexExists(): bool
    {
        $db = static::getDb();
        $command = $db->createCommand();
        return (bool)$command->indexExists(static::index());
    }

    /**
     * @return mixed|\yii\elasticsearch\Connection
     */
    public static function getDb()
    {
        return Elasticsearch::getConnection();
    }

    /**
     * @return string
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function index(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }
        return 'craft-entries_' . static::$siteId;
    }

    /**
     * Create this model's index in Elasticsearch
     * @param array $schema The Elascticsearch index definition schema
     * @param bool $force
     * @throws InvalidConfigException If the `$siteId` isn't set
     * @throws \yii\elasticsearch\Exception If an error occurs while communicating with the Elasticsearch server
     */
    public static function createIndex(array $schema, $force = false)
    {
        $db = static::getDb();
        $command = $db->createCommand();

        if ($force === true && $command->indexExists(static::index())) {
            self::deleteIndex();
        }

        $db->delete('_ingest/pipeline/attachment');
        $db->put('_ingest/pipeline/attachment', [], Json::encode([
            'description' => 'Extract attachment information',
            'processors'  => [
                [
                    'attachment' => [
                        'field'          => 'content',
                        'target_field'   => 'attachment',
                        'indexed_chars'  => -1,
                        'ignore_missing' => true,
                    ],
                    'remove'     => [
                        'field' => 'content',
                    ],
                ],
            ],
        ]));
        $command->createIndex(static::index(), $schema);
    }

    /**
     * Delete this model's index
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function deleteIndex()
    {
        $db = static::getDb();
        $command = $db->createCommand();
        if ($command->indexExists(static::index())) {
            $command->deleteIndex(static::index());
        }
    }

    /**
     * @return array
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function mapping(): array
    {
        $analyzer = self::siteAnalyzer();
        $mapping = [
            static::type() => [
                'properties' => [
                    'title'      => [
                        'type'     => 'text',
                        'analyzer' => $analyzer,
                        'store'    => true,
                    ],
                    'section'    => [
                        'type'  => 'keyword',
                        'store' => true,
                    ],
                    'url'        => [
                        'type'  => 'text',
                        'store' => true,
                    ],
                    'content'    => [
                        'type'     => 'text',
                        'analyzer' => $analyzer,
                        'store'    => true,
                    ],
                    'attachment' => [
                        'properties' => [
                            'content' => [
                                'type'     => 'text',
                                'analyzer' => $analyzer,
                                'store'    => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $mapping;
    }

    /**
     * @return mixed
     */
    public function getSchema()
    {
        return $this->_schema;
    }

    /**
     * @param mixed $schema
     */
    public function setSchema($schema)
    {
        $this->_schema = $schema;
    }

    public function addAttributes(array $attributes)
    {
        $this->_attributes = ArrayHelper::merge($this->_attributes, $attributes);
    }

    /**
     * @return Element
     */
    public function getElement(): Element
    {
        return $this->_element;
    }

    /**
     * @param mixed $element
     */
    public function setElement(Element $element)
    {
        $this->_element = $element;
    }
}
