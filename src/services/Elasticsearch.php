<?php
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
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\helpers\ArrayHelper;
use craft\records\Site;
use craft\services\Sites;
use craft\web\Application;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\events\ErrorEvent;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\records\ElasticsearchRecord;
use yii\elasticsearch\Exception;

/**
 * Service used to interact with the Elasticsearch instance
 * @todo: Split into more specialized services (index management, entry indexer…)
 */
class Elasticsearch extends Component
{
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
     * Check whether or not Elasticsearch is in sync with Craft
     * @noinspection PhpDocMissingThrowsInspection
     * @return bool `true` if Elasticsearch is in sync with Craft, `false` otherwise.
     */
    public function isIndexInSync(): bool
    {
        $application = Craft::$app;

        try {
            $inSync = $application->cache->getOrSet(self::getSyncCachekey(), function () {
                Craft::debug('isIndexInSync cache miss', __METHOD__);

                if ($this->testConnection() === false) {
                    return false;
                }

                /** @noinspection NullPointerExceptionInspection NPE cannot happen here */
                $blacklistedEntryTypes = ElasticsearchPlugin::getInstance()->getSettings()->blacklistedEntryTypes;

                $sites = Craft::$app->getSites();
                foreach ($sites->getAllSites() as $site) {
                    $sites->setCurrentSite($site);
                    ElasticsearchRecord::$siteId = $site->id;

                    $countEntries = (int)Entry::find()
                        ->status(Entry::STATUS_ENABLED)
                        ->typeId(ArrayHelper::merge(['not'], $blacklistedEntryTypes))
                        ->count();
                    if (ElasticsearchPlugin::getInstance()->isCommerceEnabled()) {
                        $countEntries += (int)Product::find()
                            ->status(Entry::STATUS_ENABLED)
                            ->count();
                    }
                    $countEsRecords = (int)ElasticsearchRecord::find()->count();

                    Craft::debug("Active elements count for site #{$site->id}: {$countEntries}", __METHOD__);
                    Craft::debug("Elasticsearch records count for site #{$site->id}: {$countEsRecords}", __METHOD__);

                    if ($countEntries !== $countEsRecords) {
                        Craft::debug('Elasticsearch reindex needed!', __METHOD__);
                        return false;
                    }
                }

                return true;
            }, 300);

            return $inSync;
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (Exception $e) {
            /** @noinspection NullPointerExceptionInspection */
            $elasticsearchEndpoint = ElasticsearchPlugin::getInstance()->getSettings()->elasticsearchEndpoint;

            Craft::error(sprintf('Cannot connect to Elasticsearch host "%s".', $elasticsearchEndpoint), __METHOD__);

            if ($application instanceof Application) {
                /** @noinspection PhpUnhandledExceptionInspection Cannot happen as craft\web\getSession() never throws */
                $application->getSession()->setError(Craft::t(
                    ElasticsearchPlugin::PLUGIN_HANDLE,
                    'Could not connect to the Elasticsearch server at {elasticsearchEndpoint}. Please check the host and authentication settings.',
                    ['elasticsearchEndpoint' => $elasticsearchEndpoint]
                ));
            }

            return false;
        }
    }


    // Index Management - Methods related to creating/removing indexes
    // =========================================================================


    /**
     * Create an Elasticsearch index for the giver site
     * @noinspection PhpDocMissingThrowsInspection Cannot happen since we DO set the siteId property
     * @param int $siteId
     * @throws \yii\elasticsearch\Exception If an error occurs while communicating with the Elasticsearch server
     */
    public function createSiteIndex(int $siteId)
    {
        Craft::info("Creating an Elasticsearch index for the site #{$siteId}", __METHOD__);

        ElasticsearchRecord::$siteId = $siteId;
        $esRecord = new ElasticsearchRecord(); // Needed to trigger according event
        $esRecord->createESIndex();
    }

    /**
     * Remove the Elasticsearch index for the given site
     * @noinspection PhpDocMissingThrowsInspection Cannot happen since we DO set the siteId property
     * @param int $siteId
     */
    public function removeSiteIndex(int $siteId)
    {
        Craft::info("Removing the Elasticsearch index for the site #{$siteId}", __METHOD__);
        ElasticsearchRecord::$siteId = $siteId;
        /** @noinspection PhpUnhandledExceptionInspection Cannot happen since we DO set the siteId property */
        ElasticsearchRecord::deleteIndex();
    }

    /**
     * Re-create the Elasticsearch index of sites matching any of `$siteIds`
     * @param int[] $siteIds
     */
    public function recreateSiteIndex(int ...$siteIds)
    {
        foreach ($siteIds as $siteId) {
            try {
                $this->removeSiteIndex($siteId);
                $this->createSiteIndex($siteId);
            } catch (Exception $e) {
                $this->triggerErrorEvent($e);
            }
        }
    }


    // Index Manipulation - Methods related to adding to / removing from the index
    // =========================================================================

    /**
     * Index the given `$element` into Elasticsearch
     * @param Element $element
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     * @throws Exception If an error occurs while saving the record to the Elasticsearch server
     * @throws IndexElementException If an error occurs while getting the indexable content of the entry. Check the previous property of the exception for more details
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     */
    public function indexElement(Element $element)
    {
        $reason = $this->getReasonForNotReindexing($element);
        if ($reason !== null) {
            return $reason;
        }

        Craft::info("Indexing entry {$element->url}", __METHOD__);

        $esRecord = $this->getElasticRecordForElement($element);
        $esRecord->title = $element->title;
        $esRecord->url = $element->url;

        $html = $this->getElementIndexableContent($element);
        if ($html === false) {
            $message = "Not indexing entry #{$element->id} since it doesn't have a template.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        $esRecord->content = base64_encode(trim($html));

        $isSuccessfullySaved = $esRecord->save();

        if (!$isSuccessfullySaved) {
            throw new Exception('Could not save elasticsearch record');
        }

        return null;
    }


    /**
     * Removes an entry from  the Elasticsearch index
     * @param Element $element The entry to delete
     * @return int The number of rows deleted
     * @throws Exception If the entry to be deleted cannot be found
     */
    public function deleteElement(Element $element): int
    {
        Craft::info("Deleting entry #{$element->id}: {$element->url}", __METHOD__);

        ElasticsearchRecord::$siteId = $element->siteId;

        return ElasticsearchRecord::deleteAll(['_id' => $element->id]);
    }


    /**
     * Execute the given `$query` in the Elasticsearch index
     * @param string $query String to search for
     * @param int|null $siteId Site id to make the search
     * @return ElasticsearchRecord[]
     * @throws IndexElementException
     *                         todo: Specific exception
     */
    public function search(string $query, $siteId = null): array
    {
        if ($query === null) {
            return [];
        }

        if ($siteId === null) {
            try {
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
            } catch (SiteNotFoundException $e) {
                throw new IndexElementException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'Cannot fetch the id of the current site. Please make sure at least one site is enabled.'
                ), 0, $e);
            }
        }

        ElasticsearchRecord::$siteId = $siteId;
        try {
            $esRecord = new ElasticsearchRecord();
            $results = $esRecord->search($query);
            $output = [];
            $callback = ElasticsearchPlugin::getInstance()->getSettings()->resultFormatterCallback;

            // Get the list of extra fields
            $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
            $additionalFields = empty($extraFields) ? [] : array_keys($extraFields);

            foreach ($results as $result) {
                $formattedResult = [
                    'id'         => $result->getPrimaryKey(),
                    'title'      => $result->title,
                    'url'        => $result->url,
                    'score'      => $result->score,
                    'highlights' => $result->highlight ? array_merge(...array_values($result->highlight)) : [],
                    'rawResult'  => $result
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
            throw new IndexElementException(Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'An error occurred while running the "{searchQuery}" search query on Elasticsearch instance: {previousExceptionMessage}',
                ['previousExceptionMessage' => $e->getMessage(), 'searchQuery' => $query]
            ), 0, $e);
        }
    }

    protected static function getSyncCachekey(): string
    {
        return self::class . '_isSync';
    }

    /**
     * @param Element $element
     * @return ElasticsearchRecord
     */
    protected function getElasticRecordForElement(Element $element): ElasticsearchRecord
    {
        ElasticsearchRecord::$siteId = $element->siteId;
        $esRecord = ElasticsearchRecord::findOne($element->id);

        if (empty($esRecord)) {
            $esRecord = new ElasticsearchRecord();
            ElasticsearchRecord::$siteId = $element->siteId;
            $esRecord->setPrimaryKey($element->id);
        }
        $esRecord->setElement($element);
        return $esRecord;
    }


    /**
     * Get an element page content using Guzzle
     * @param Element $element
     * @return bool|string The indexable content of the entry or `false` if the entry doesn't have a template (ie. is not indexable)
     * @throws IndexElementException If anything goes wrong. Check the previous property of the exception to get more details
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getElementIndexableContent(Element $element)
    {
        Craft::debug('Getting element page content : ' . $element->url, __METHOD__);
        $client = new Client([
            'connect_timeout' => 10
        ]);
        try {
            $res = $client->request('GET', $element->url);
            if ($res->getStatusCode() === 200) {
                return $this->extractIndexablePart($res->getBody());
            }
        } catch (RequestException $e) {
            Craft::error('Could not get element content: ' . $e->getMessage(), __METHOD__);
            throw new IndexElementException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new IndexElementException(Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'An error occurred while parsing the element page content: {previousExceptionMessage}',
                ['previousExceptionMessage' => $e->getMessage()]
            ), 0, $e);
        }
        return false;
    }


    /**
     * @param $html
     * @return string
     */
    protected function extractIndexablePart(string $html): string
    {
        /** @noinspection NullPointerExceptionInspection NPE cannot happen here. */
        if ($callback = ElasticsearchPlugin::getInstance()->getSettings()->contentExtractorCallback) {
            $html = $callback($html);
        }

        if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $html, $body)) {
            $html = '<!DOCTYPE html>' . trim($body[1]);
        }

        return trim($html);
    }

    /**
     * Get the reason why an entry should NOT be reindex.
     * @param Element $element The element to consider for reindexing
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     */
    protected function getReasonForNotReindexing(Element $element)
    {
        if (!($element instanceof Entry || $element instanceof Product)) {
            $message = "Not indexing entry #{$element->id} since it is not an entry or a product.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        if ($element->status !== Entry::STATUS_LIVE) {
            $message = "Not indexing entry #{$element->id} since it is not published.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        if (!$element->enabledForSite) {
            /** @var Sites $sitesService */
            $sitesService = Craft::$app->getSites();
            try {
                $currentSiteId = $sitesService->getCurrentSite()->id;
                $message = "Not indexing entry #{$element->id} since it is not enabled for the current site (#{$currentSiteId}).";
                Craft::debug($message, __METHOD__);
                return $message;
            } catch (SiteNotFoundException $e) {
                $message = "Not indexing entry #{$element->id} since there are no sites (therefore it can't be enabled for any site).";
                Craft::debug($message, __METHOD__);
                return $message;
            }
        }

        if (!$element->hasContent()) {
            $message = "Not indexing entry #{$element->id} since it has no content.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        /** @noinspection NullPointerExceptionInspection NPE cannot happen here. */
        if ($element instanceof Entry) {
            $blacklist = ElasticsearchPlugin::getInstance()->getSettings()->blacklistedEntryTypes;
            if (in_array($element->typeId, $blacklist)) {
                $message = "Not indexing entry #{$element->id} since it's in a blacklisted entry types.";
                Craft::debug($message, __METHOD__);
                return $message;
            }
        }

        return null;
    }

    /**
     * Return a list of enabled entries an array of elements descriptors
     * @param int[]|null $siteIds An array containing the ids of sites to be or
     *                            reindexed, or `null` to reindex all sites.
     * @return array An array of entry descriptors. An entry descriptor is an
     *                            associative array with the `elementId` and `siteId` keys.
     */
    public function getEnabledEntries($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $entries = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = Entry::find()
                ->select(['elements.id as elementId', 'elements_sites.siteId'])
                ->siteId($siteId)
                ->asArray(true)
                ->all();
            $entries = ArrayHelper::merge($entries, $siteEntries);
        }
        array_walk($entries, function (&$entry) {
            $entry['type'] = Entry::class;
        });

        return $entries;
    }

    /**
     * Return a list of enabled products an array of elements descriptors
     * @param int[]|null $siteIds An array containing the ids of sites to be or
     *                            reindexed, or `null` to reindex all sites.
     * @return array An array of elements descriptors. An entry descriptor is an
     *                            associative array with the `elementId` and `siteId` keys.
     */
    public function getEnabledProducts($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $products = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = Product::find()
                ->select(['commerce_products.id as elementId', 'elements_sites.siteId'])
                ->siteId($siteId)
                ->asArray(true)
                ->all();
            $products = ArrayHelper::merge($products, $siteEntries);
        }
        array_walk($products, function (&$product) {
            $product['type'] = Product::class;
        });
        return $products;
    }

    /**
     * Create an empty Elasticsearch index for all sites. Existing indexes will be deleted and recreated.
     * @throws IndexElementException If the Elasticsearch index of a site cannot be recreated
     */
    public function recreateIndexesForAllSites()
    {
        $siteIds = Site::find()->select('id')->column();

        if (!empty($siteIds)) {
            try {
                $this->recreateSiteIndex(...$siteIds);
            } catch (\Exception $e) {
                throw new IndexElementException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'Cannot recreate empty indexes for all sites'
                ), 0, $e);
            }
        }

        Craft::$app->getCache()->delete(self::getSyncCachekey()); // Invalidate cache
    }

    protected function triggerErrorEvent(Exception $e)
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

}
