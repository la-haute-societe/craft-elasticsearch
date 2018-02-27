<?php
/**
 * @link http://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\records;

use Craft;
use lhs\elasticsearch\Elasticsearch;
use yii\base\InvalidConfigException;
use yii\elasticsearch\ActiveRecord;
use yii\helpers\Json;

/**
 * News class
 *
 * @author albanjubert
 **/
class ElasticsearchRecord extends ActiveRecord
{
    public static $siteId;

    /**
     * @param bool $runValidation
     * @param null $attributeNames
     * @return bool
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        self::checkIndex();
        if (!$this->getIsNewRecord()) {
            $this->delete(); // pipeline in not supported by Document Update API :(
        }
        return $this->insert($runValidation, $attributeNames, ["pipeline" => "attachment"]);
    }

    /**
     * Check if the Elasticsearch index already exists or created it if not
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\elasticsearch\Exception
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
        return Elasticsearch::$plugin->connection;
    }

    /**
     * @return string
     * @throws InvalidConfigException
     */
    public static function index()
    {
        if (null === static::$siteId) {
            throw new InvalidConfigException('siteId was not set');
        }
        return parent::index() . '_' . static::$siteId;
    }

    /**
     * Create this model's index
     * @param bool $force
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\elasticsearch\Exception
     */
    public static function createIndex($force = false)
    {
        $db = static::getDb();
        $command = $db->createCommand();

        if ($force === true && $command->indexExists(static::index())) {
            self::deleteIndex();
        }

        $db->delete("_ingest/pipeline/attachment");
        $db->put("_ingest/pipeline/attachment", [], Json::encode([
            "description" => "Extract attachment information",
            "processors"  => [
                [
                    "attachment" => [
                        "field"          => "content",
                        "target_field"   => "attachment",
                        "indexed_chars"  => -1,
                        "ignore_missing" => true
                    ],
                    "remove"     => [
                        "field" => "content"
                    ]
                ],
            ]
        ]));
        $command->createIndex(static::index(), [
            'mappings' => static::mapping(),
        ]);
    }

    /**
     * Delete this model's index
     */
    public static function deleteIndex()
    {
        $db = static::getDb();
        $command = $db->createCommand();
        if ($command->indexExists(static::index())) {
            $command->deleteIndex(static::index(), static::type());
        }
    }

    public static function mapping()
    {
        $analyzer = self::siteAnalyzer();
        return [
            static::type() => [
                'properties' => [
                    'title'      => [
                        'type'     => 'text',
                        'analyzer' => $analyzer,
                        'store'    => true
                    ],
                    'section'    => [
                        'type'  => 'keyword',
                        'store' => true
                    ],
                    'url'        => [
                        'type'  => 'text',
                        'store' => true
                    ],
                    'content'    => [
                        'type'     => 'text',
                        'analyzer' => $analyzer,
                        'store'    => true
                    ],
                    'attachment' => [
                        'properties' => [
                            'content' => [
                                'type'     => 'text',
                                'analyzer' => $analyzer,
                                'store'    => true
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Try to guess the best Elasticsearch analyze for the current site language
     * @return string
     */
    public static function siteAnalyzer()
    {
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
     * @inheritdoc
     */
    public function attributes()
    {
        return ['title', 'url', 'section', 'content'];
    }

}