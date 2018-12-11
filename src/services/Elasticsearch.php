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
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\helpers\ArrayHelper;
use craft\records\Site;
use craft\services\Sites;
use craft\web\Application;
use craft\web\View;
use DateTime;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\events\ErrorEvent;
use lhs\elasticsearch\exceptions\IndexEntryException;
use lhs\elasticsearch\records\ElasticsearchRecord;
use Twig_Error_Loader;
use yii\base\InvalidConfigException;
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
                    $countEsRecords = (int)ElasticsearchRecord::find()->count();

                    Craft::debug("Active entry count for site #{$site->id}: {$countEntries}", __METHOD__);
                    Craft::debug("Elasticsearch record count for site #{$site->id}: {$countEsRecords}", __METHOD__);

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
        /** @noinspection PhpUnhandledExceptionInspection Cannot happen since we DO set the siteId property */
        ElasticsearchRecord::createIndex();
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
     * Index the given `$entry` into Elasticsearch
     * @param Entry $entry
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     * @throws IndexEntryException If an error occurs while getting the indexable content of the entry. Check the previous property of the exception for more details
     * @throws Exception If an error occurs while saving the record to the Elasticsearch server
     */
    public function indexEntry(Entry $entry)
    {
        $reason = $this->getReasonForNotReindexing($entry);
        if ($reason !== null) {
            return $reason;
        }

        Craft::info("Indexing entry {$entry->url}", __METHOD__);

        $esRecord = $this->getElasticRecordForEntry($entry);
        $esRecord->title = $entry->title;
        $esRecord->url = $entry->url;

        $html = $this->getEntryIndexableContent($entry);
        if ($html === false) {
            $message = "Not indexing entry #{$entry->id} since it doesn't have a template.";
            Craft::debug($message, __METHOD__);
            return $message;
        }


        $esRecord->content = base64_encode(trim($html));

        try {
            $isSuccessfullySaved = $esRecord->save();
        } catch (\Exception $e) {
            throw new Exception('Could not save elasticsearch record', 0, $e);
        }

        if (!$isSuccessfullySaved) {
            throw new Exception('Could not save elasticsearch record', $esRecord->errors);
        }

        return null;
    }


    /**
     * Removes an entry from  the Elasticsearch index
     * @param Entry $entry The entry to delete
     * @return int The number of rows deleted
     * @throws Exception If the entry to be deleted cannot be found
     */
    public function deleteEntry(Entry $entry): int
    {
        Craft::info("Deleting entry #{$entry->id}: {$entry->url}", __METHOD__);

        ElasticsearchRecord::$siteId = $entry->siteId;

        return ElasticsearchRecord::deleteAll(['_id' => $entry->id]);
    }


    /**
     * Execute the given `$query` in the Elasticsearch index
     * @param string   $query  String to search for
     * @param int|null $siteId Site id to make the search
     * @return ElasticsearchRecord[]
     * @throws IndexEntryException
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
                throw new IndexEntryException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'Cannot fetch the id of the current site. Please make sure at least one site is enabled.'
                ), 0, $e);
            }
        }

        ElasticsearchRecord::$siteId = $siteId;
        try {
            $results = ElasticsearchRecord::search($query);
            $output = [];
            foreach ($results as $result) {
                $output[] = [
                    'id'         => $result->getPrimaryKey(),
                    'title'      => $result->title,
                    'url'        => $result->url,
                    'score'      => $result->score,
                    'highlights' => $result->highlight['attachment.content'] ?? [],
                ];
            }

            return $output;
        } catch (\Exception $e) {
            throw new IndexEntryException(Craft::t(
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
     * @param Entry $entry
     * @return ElasticsearchRecord
     */
    protected function getElasticRecordForEntry(Entry $entry): ElasticsearchRecord
    {
        ElasticsearchRecord::$siteId = $entry->siteId;
        $esRecord = ElasticsearchRecord::findOne($entry->id);

        if (empty($esRecord)) {
            $esRecord = new ElasticsearchRecord();
            ElasticsearchRecord::$siteId = $entry->siteId;
            $esRecord->setPrimaryKey($entry->id);
        }

        return $esRecord;
    }

    /**
     * @param Entry $entry
     * @return string|false The indexable content of the entry or `false` if the entry doesn't have a template (ie. is not indexable)
     * @throws IndexEntryException If anything goes wrong. Check the previous property of the exception to get more details
     */
    protected function getEntryIndexableContent(Entry $entry): string
    {
        $sitesService = Craft::$app->getSites();
        $view = Craft::$app->getView();

        $site = $sitesService->getSiteById($entry->siteId);
        if (!$site) {
            throw new IndexEntryException(Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'Invalid site id: {siteId}',
                ['siteId' => $entry->siteId]
            ));
        }
        $sitesService->setCurrentSite($site);

        Craft::$app->language = $site->language;

        if (!$entry->postDate) {
            $entry->postDate = new DateTime();
        }

        // Have this entry override any freshly queried entries with the same ID/site ID
        $elementsService = Craft::$app->getElements();
        $elementsService->setPlaceholderElement($entry);

        // Switch to site template rendering mode
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        } catch (\yii\base\Exception $e) {
            // Shouldn't happen as a constant is used for the templateMode parameter
            throw new IndexEntryException($e->getMessage(), 0, $e);
        }
        // Disable template caching before rendering.
        // Without this, templates using the `{% cache %}` Twig tag may have a strange behavior.
        $craftGeneralConfig = Craft::$app->getConfig()->getGeneral();
        $craftGeneralConfig->enableTemplateCaching = false;

        try {
            $sectionSiteSettings = $entry->getSection()->getSiteSettings();

            $templateName = null;
            if (array_key_exists($entry->siteId, $sectionSiteSettings)) {
                $templateName = $sectionSiteSettings[$entry->siteId]->template;
            }

            if ($templateName === null) {
                return false;
            }

            try {
                $html = trim($view->renderTemplate($templateName, [
                    'entry' => $entry,
                ]));

                // Re-enable template caching. On est pas des bêtes !
                $craftGeneralConfig->enableTemplateCaching = false;
                // Restore template rendering mode
                try {
                    $view->setTemplateMode(View::TEMPLATE_MODE_CP);
                } catch (\yii\base\Exception $e) {
                    // Shouldn't happen as a constant is used for the templateMode parameter
                    throw new IndexEntryException($e->getMessage(), 0, $e);
                }

                $html = $this->extractIndexablePartFromEntryContent($html);

                return $html;
            } catch (Twig_Error_Loader $e) {
                throw new IndexEntryException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'The entry #{entryId} uses an invalid Twig template: {twigTemplateName}',
                    ['entryId' => $entry->id, 'twigTemplateName' => $templateName]
                ), 0, $e);
            } catch (\yii\base\Exception $e) {
                throw new IndexEntryException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'An error occurred while rendering the {twigTemplateName} Twig template: {previousExceptionMessage}',
                    ['twigTemplateName' => $templateName, 'previousExceptionMessage' => $e->getMessage()]
                ), 0, $e);
            }
        } catch (InvalidConfigException $e) {
            throw new IndexEntryException(Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'The entry #{entryId} has an incorrect section id: #{sectionId}',
                ['entryId' => $entry->id, 'sectionId' => $entry->sectionId]
            ), 0, $e);
        }
    }

    /**
     * @param $html
     * @return string
     */
    protected function extractIndexablePartFromEntryContent(string $html): string
    {
        /** @noinspection NullPointerExceptionInspection NPE cannot happen here. */
        if ($callback = ElasticsearchPlugin::getInstance()->getSettings()->contentExtractorCallback) {
            return $callback($html);
        }

        if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $html, $body)) {
            $html = '<!DOCTYPE html>' . trim($body[1]);
        }

        return $html;
    }

    /**
     * Get the reason why an entry should NOT be reindex.
     * @param Entry $entry The entry to consider for reindexing
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     */
    protected function getReasonForNotReindexing(Entry $entry)
    {
        if ($entry->status !== Entry::STATUS_LIVE) {
            $message = "Not indexing entry #{$entry->id} since it is not published.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        if (!$entry->enabledForSite) {
            /** @var Sites $sitesService */
            $sitesService = Craft::$app->getSites();
            try {
                $currentSiteId = $sitesService->getCurrentSite()->id;
                $message = "Not indexing entry #{$entry->id} since it is not enabled for the current site (#{$currentSiteId}).";
                Craft::debug($message, __METHOD__);
                return $message;
            } catch (SiteNotFoundException $e) {
                $message = "Not indexing entry #{$entry->id} since there are no sites (therefore it can't be enabled for any site).";
                Craft::debug($message, __METHOD__);
                return $message;
            }
        }

        if (!$entry->hasContent()) {
            $message = "Not indexing entry #{$entry->id} since it has no content.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        /** @noinspection NullPointerExceptionInspection NPE cannot happen here. */
        $blacklist = ElasticsearchPlugin::getInstance()->getSettings()->blacklistedEntryTypes;
        if (in_array($entry->typeId, $blacklist)) {
            $message = "Not indexing entry #{$entry->id} since it's in a blacklisted entry types.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        return null;
    }

    /**
     * @param int[]|null $siteIds An array containing the ids of sites to be or
     *                            reindexed, or `null` to reindex all sites.
     * @return array An array of entry descriptors. An entry descriptor is an
     *                            associative array with the `entryId` and `siteId` keys.
     */
    public function getEnabledEntries($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $entries = [];
        foreach ($siteIds as $siteId) {
            $siteEntries = Entry::find()
                ->select(['{{%elements}}.id as entryId', '{{%elements_sites}}.siteId'])
                ->siteId($siteId)
                ->asArray(true)
                ->all();
            $entries = ArrayHelper::merge($entries, $siteEntries);
        }

        return $entries;
    }

    /**
     * Create an empty Elasticsearch index for all sites. Existing indexes will be deleted and recreated.
     * @throws IndexEntryException If the Elasticsearch index of a site cannot be recreated
     */
    public function recreateIndexesForAllSites()
    {
        $siteIds = Site::find()->select('id')->column();

        try {
            $this->recreateSiteIndex(...$siteIds);
        } catch (\Exception $e) {
            throw new IndexEntryException(Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'Cannot recreate empty indexes for all sites'
            ), 0, $e);
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
