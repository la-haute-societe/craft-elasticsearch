<?php
/** @noinspection PhpUndefinedNamespaceInspection */

/** @noinspection PhpUndefinedClassInspection */

/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\helpers\ArrayHelper;
use craft\web\Application;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\events\ErrorEvent;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\models\IndexableElementModel;
use lhs\elasticsearch\records\ElasticsearchRecord;

/**
 * Service used to interact with the Elasticsearch instance
 */
class ElasticsearchService extends Component
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    // Elasticsearch / Craft communication
    // =========================================================================

    /**
     * Test the connection to the Elasticsearch server, optionally using the given $httpAddress instead of the one
     * currently in use in the yii2-elasticsearch instance.
     * @return boolean `true` if the connection succeeds, `false` otherwise.
     */
    public function testConnection(): bool
    {
        try {
            $elasticConnection = ElasticsearchPlugin::getConnection();
            if (count($elasticConnection->nodes) < 1) {
                return false;
            }

            $elasticConnection->open();
            $elasticConnection->activeNode = array_keys($elasticConnection->nodes)[0];
            $elasticConnection->getNodeInfo();
            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            if (isset($elasticConnection)) {
                $elasticConnection->close();
            }
        }
    }

    /**
     * Check whether Elasticsearch is in sync with Craft
     * @return bool `true` if Elasticsearch is in sync with Craft, `false` otherwise.
     * @noinspection PhpRedundantCatchClauseInspection \yii\elasticsearch\Exception may be thrown by \yii\elasticsearch\Connection::get()
     */
    public function isIndexInSync(): bool
    {
        $application = Craft::$app;

        try {
            return $application->cache->getOrSet(
                self::getSyncCachekey(),
                function (): int {
                    Craft::debug('isIndexInSync cache miss', __METHOD__);

                    if ($this->testConnection() === false) {
                        return false;
                    }

                    $sites = Craft::$app->getSites();
                    $outOfSync = false;
                    foreach ($sites->getAllSites() as $site) {
                        $sites->setCurrentSite($site);
                        ElasticsearchRecord::$siteId = $site->id;

                        Craft::debug('Checking Elasticsearch index for site #' . $site->id, __METHOD__);

                        $countIdexableElements = $this->countIndexableEntries($site->id);
                        $countEsRecords = (int)ElasticsearchRecord::find()->count();

                        Craft::debug(
                            sprintf(
                                'Site %s: %d indexable elements, %d in Elasticsearch index.',
                                $site->handle,
                                $countIdexableElements,
                                $countEsRecords
                            ),
                            __METHOD__
                        );

                        if ($countIdexableElements !== $countEsRecords) {
                            $outOfSync = true;
                        }
                    }
                    if ($outOfSync === true) {
                        Craft::debug('Elasticsearch reindex needed!', __METHOD__);
                        return false;
                    }

                    return true;
                },
                300
            );
        } catch (\yii\elasticsearch\Exception $e) {
            $elasticsearchEndpoint = $this->plugin->getSettings()->elasticsearchEndpoint;

            Craft::error(sprintf('Cannot connect to Elasticsearch host "%s".', $elasticsearchEndpoint), __METHOD__);

            if ($application instanceof Application) {
                $application->getSession()->setError(
                    Craft::t(
                        ElasticsearchPlugin::PLUGIN_HANDLE,
                        'Could not connect to the Elasticsearch server at {elasticsearchEndpoint}. Please check the host and authentication settings.',
                        ['elasticsearchEndpoint' => $elasticsearchEndpoint]
                    )
                );
            }

            return false;
        }
    }


    // Index Manipulation - Methods related to adding to / removing from the index
    // =========================================================================


    /**
     * Execute the given `$query` in the Elasticsearch index
     * @param string   $query  String to search for
     * @param int|null $siteId Site id to make the search
     * @return ElasticsearchRecord[]
     * @throws IndexElementException
     *                         TODO: Throw a more specific exception
     */
    public function search(string $query, $siteId = null): array
    {
        if ($query === '') {
            return [];
        }

        if ($siteId === null) {
            try {
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
            } catch (SiteNotFoundException $e) {
                throw new IndexElementException(
                    Craft::t(
                        ElasticsearchPlugin::PLUGIN_HANDLE,
                        'Cannot fetch the id of the current site. Please make sure at least one site is enabled.'
                    ), 0, $e
                );
            }
        }

        ElasticsearchRecord::$siteId = $siteId;
        try {
            $esRecord = new ElasticsearchRecord();
            $results = $esRecord->search($query);
            $output = [];
            $callback = $this->plugin->getSettings()->resultFormatterCallback;

            // Get the list of extra fields
            $extraFields = $this->plugin->getSettings()->extraFields;
            $additionalFields = empty($extraFields) ? [] : array_keys($extraFields);

            foreach ($results as $result) {
                $formattedResult = [
                    'id'            => $result->get_id(),
                    'title'         => $result->title,
                    'url'           => $result->url,
                    'postDate'      => $result->postDate,
                    'expiryDate'    => $result->expiryDate,
                    'elementHandle' => $result->elementHandle,
                    'score'         => $result->score,
                    'highlights'    => $result->highlight ? array_merge(...array_values($result->highlight)) : [],
                    'rawResult'     => $result,
                ];

                // Add extra fields to the current result
                $additionalFormattedResult = [];
                foreach ($additionalFields as $additionalField) {
                    $additionalFormattedResult[$additionalField] = $result->$additionalField;
                }
                $formattedResult = ArrayHelper::merge($additionalFormattedResult, $formattedResult); // Do not override the default results

                if ($callback) {
                    $formattedResult = $callback($formattedResult, $result);
                }
                $output[] = $formattedResult;
            }

            return $output;
        } catch (\Exception $e) {
            throw new IndexElementException(
                Craft::t(
                    ElasticsearchPlugin::PLUGIN_HANDLE,
                    'An error occurred while running the "{searchQuery}" search query on Elasticsearch instance: {previousExceptionMessage}',
                    ['previousExceptionMessage' => $e->getMessage(), 'searchQuery' => $query]
                ), 0, $e
            );
        }
    }

    /**
     * @param int[]|null $siteIds
     * @return array
     */
    public function getIndexableElementModels($siteIds = null): array
    {
        $models = $this->getIndexableEntryModels($siteIds);
        $models = \yii\helpers\ArrayHelper::merge($models, $this->getIndexableAssetModels($siteIds));
        if ($this->plugin->isCommerceEnabled()) {
            $models = ArrayHelper::merge($models, $this->getIndexableProductModels($siteIds));

            if ($this->plugin->isDigitalProductsEnabled()) {
                $models = ArrayHelper::merge($models, $this->getIndexableDigitalProductModels($siteIds));
            }
        }

        return $models;
    }

    /**
     * Return a list of enabled entries an array of elements descriptors
     * @param int[]|null $siteIds An array containing the ids of sites to be or reindexed, or `null` to reindex all sites.
     * @return array An array of entry descriptors. An entry descriptor is an associative array with the `elementId`, `siteId` and `type` keys.
     */
    public function getIndexableEntryModels($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $entries = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = $this->getIndexableEntriesQuery($siteId)
                ->select(['elements.id as elementId', 'elements_sites.siteId'])
                ->asArray(true)
                ->all();
            $entries = ArrayHelper::merge($entries, $siteEntries);
        }

        return array_map(
            static function ($entry): IndexableElementModel {
                $model = new IndexableElementModel();
                $model->elementId = $entry['elementId'];
                $model->siteId = $entry['siteId'];
                $model->type = Entry::class;
                return $model;
            },
            $entries
        );
    }

    /**
     * Return a list of enabled products an array of elements descriptors
     * @param int[]|null $siteIds An array containing the ids of sites to be or
     *                            reindexed, or `null` to reindex all sites.
     * @return array An array of elements descriptors. An entry descriptor is an
     *                            associative array with the `elementId` and `siteId` keys.
     */
    public function getIndexableProductModels($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $products = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = $this->getIndexableProductsQuery($siteId)
                ->select(['commerce_products.id as elementId', 'elements_sites.siteId'])
                ->asArray(true)
                ->all();
            $products = ArrayHelper::merge($products, $siteEntries);
        }

        return array_map(
            static function ($entry): IndexableElementModel {
                $model = new IndexableElementModel();
                $model->elementId = $entry['elementId'];
                $model->siteId = $entry['siteId'];
                $model->type = Product::class;
                return $model;
            },
            $products
        );
    }

    /**
     * Return a list of enabled digital products an array of elements descriptors
     * @param int[]|null $siteIds An array containing the ids of sites to be or
     *                            reindexed, or `null` to reindex all sites.
     * @return array An array of elements descriptors. An entry descriptor is an
     *                            associative array with the `elementId` and `siteId` keys.
     */
    public function getIndexableDigitalProductModels($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $digitalProducts = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = $this->getIndexableDigitalProductsQuery($siteId)
                ->select(['digitalproducts_products.id as elementId', 'elements_sites.siteId'])
                ->asArray(true)
                ->all();
            $digitalProducts = ArrayHelper::merge($digitalProducts, $siteEntries);
        }

        return array_map(
            static function ($entry): IndexableElementModel {
                $model = new IndexableElementModel();
                $model->elementId = $entry['elementId'];
                $model->siteId = $entry['siteId'];
                $model->type = DigitalProduct::class;
                return $model;
            },
            $digitalProducts
        );
    }

    /**
     * Return a list of enabled assets as an array of elements descriptors
     * @param int[]|null $siteIds An array containing the ids of sites to be or reindexed, or `null` to reindex all sites.
     * @return array An array of asset descriptors. An asset descriptor is an associative array with the `elementId`, `siteId` and `type` keys.
     */
    public function getIndexableAssetModels($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $entries = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = $this->getIndexableAssetsQuery($siteId)
                ->select(['elements.id as elementId', 'elements_sites.siteId'])
                ->asArray(true)
                ->all();
            $entries = ArrayHelper::merge($entries, $siteEntries);
        }

        return array_map(
            static function ($entry): IndexableElementModel {
                $model = new IndexableElementModel();
                $model->elementId = $entry['elementId'];
                $model->siteId = $entry['siteId'];
                $model->type = Asset::class;
                return $model;
            },
            $entries
        );
    }

    public function getIndexableEntriesQuery($siteId)
    {
        return Entry::find()
            ->status([Entry::STATUS_PENDING, Entry::STATUS_LIVE])
            ->siteId($siteId)
            ->type(ArrayHelper::merge(['not'], $this->plugin->getSettings()->blacklistedEntryTypes))
            ->uri(['not', '']); // Filter out entries with no URL as they shouldn't be indexed
    }

    public function getIndexableAssetsQuery($siteId)
    {
        return Asset::find()
            ->status(Asset::STATUS_ENABLED)
            ->siteId($siteId)
            ->kind('pdf')
            ->volume(ArrayHelper::merge(['not'], $this->plugin->getSettings()->blacklistedAssetVolumes));
    }

    public function getIndexableProductsQuery($siteId)
    {
        return Product::find()
            ->status([Entry::STATUS_PENDING, Entry::STATUS_LIVE])
            ->siteId($siteId)
            ->uri(['not', '']);
    }

    public function getIndexableDigitalProductsQuery($siteId)
    {
        return DigitalProduct::find()
            ->status([Entry::STATUS_PENDING, Entry::STATUS_LIVE])
            ->siteId($siteId)
            ->uri(['not', '']);
    }

    public static function getSyncCachekey(): string
    {
        return self::class . '_isSync';
    }

    protected function triggerErrorEvent(\yii\elasticsearch\Exception $e): void
    {
        if (
            isset($e->errorInfo['responseBody']['error']['reason'])
            && $e->errorInfo['responseBody']['error']['reason'] === 'No processor type exists with name [attachment]'
        ) {
            /** @noinspection NullPointerExceptionInspection */
            ElasticsearchPlugin::getInstance()->trigger(
                ElasticsearchPlugin::EVENT_ERROR_NO_ATTACHMENT_PROCESSOR,
                new ErrorEvent($e)
            );
        }
    }

    /**
     * @param int $siteId
     * @return int
     */
    protected function countIndexableEntries(int $siteId): int
    {
        $countEntries = (int)$this->getIndexableEntriesQuery($siteId)->count();
        $countAssets = (int)$this->getIndexableAssetsQuery($siteId)->count();
        if ($this->plugin->isCommerceEnabled()) {
            $countProducts = (int)$this->getIndexableProductsQuery($siteId)->count();

            if ($this->plugin->isDigitalProductsEnabled()) {
                $countDigitalProducts = (int)$this->getIndexableDigitalProductsQuery($siteId)->count();
            }
        }

        return $countEntries + $countAssets + ($countProducts ?? 0) + ($countDigitalProducts ?? 0);
    }
}
