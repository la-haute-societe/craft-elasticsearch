<?php
/**
 * @link      http://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\records;

use Craft;
use craft\helpers\ArrayHelper;
use lhs\elasticsearch\Elasticsearch;
use yii\base\InvalidConfigException;
use yii\elasticsearch\ActiveRecord;
use yii\helpers\Json;

/**
 * @property string       title
 * @property string       url
 * @property mixed        section
 * @property object|array content
 */
class ElasticsearchRecord extends ActiveRecord
{
    public static $siteId;

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        return ['title', 'url', 'section', 'content'];
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

        $siteLanguage = Craft::$app->language;
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
     *
     * @return bool
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function save($runValidation = true, $attributeNames = null): bool
    {
        self::checkIndex();
        if (!$this->getIsNewRecord()) {
            $this->delete(); // pipeline in not supported by Document Update API :(
        }
        return $this->insert($runValidation, $attributeNames, ['pipeline' => 'attachment']);
    }

    /**
     * Check if the Elasticsearch index already exists or create it if not
     * @throws InvalidConfigException If the `$siteId` isn't set*
     * @throws \yii\elasticsearch\Exception If the Elasticsearch index can't be created
     */
    protected static function checkIndex()
    {
        $db = static::getDb();
        $command = $db->createCommand();
        if (!$command->indexExists(static::index())) {
            self::createIndex();
        }
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
     * @param bool $force
     * @throws InvalidConfigException If the `$siteId` isn't set
     * @throws \yii\elasticsearch\Exception If an error occurs while communicating with the Elasticsearch server
     */
    public static function createIndex($force = false)
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
        $command->createIndex(static::index(), [
            'mappings' => static::mapping(),
        ]);
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

        return [
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
    }
}
