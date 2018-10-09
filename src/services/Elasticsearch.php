<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\records\Site;
use craft\services\Sites;
use craft\web\View;
use DateTime;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\exceptions\IndexEntryException;
use lhs\elasticsearch\records\ElasticsearchRecord;
use Twig_Error_Loader;
use yii\base\InvalidConfigException;
use yii\elasticsearch\Exception;
use yii\helpers\VarDumper;

/**
 * Elasticsearch Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 */
class Elasticsearch extends Component
{
    // Elasticsearch / Craft communication
    // =========================================================================

    /**
     * Test the connection to the Elasticsearch server, optionally using the given $httpAddress instead of the one
     * currently in use in the yii2-elasticsearch instance.
     *
     * @param string|null $httpAddress An optional <hostname>:<port> to try to connect to.
     * @return boolean `true` if the connection succeeds, `false` otherwise.
     */
    public function testConnection($httpAddress = null): bool
    {
        $elasticConnection = ElasticsearchPlugin::getConnection();
        $currentElasticHttpAddress = $elasticConnection->nodes[0]['http_address'];

        if ($httpAddress !== null) {
            $elasticConnection->nodes[0]['http_address'] = $httpAddress;
        }

        try {
            $elasticConnection->open();
            return true;
        } catch (\Exception $e) {
            return false;
        }
        finally {
            $elasticConnection->close();
            $elasticConnection->nodes[0]['http_address'] = $currentElasticHttpAddress;
        }
    }

    /**
     * Check whether or not Elasticsearch is in sync with Craft
     *
     * @return bool `true` if Elasticsearch is in sync with Craft, `false` otherwise.
     */
    public function isIndexInSync(): bool
    {
        $inSync = Craft::$app->cache->getOrSet(self::getSyncCachekey(), function() {
            Craft::debug('isIndexInSync cache miss', __METHOD__);

            if ($this->testConnection() === true) {
                $sites = Craft::$app->getSites();

                foreach ($sites->getAllSites() as $site) {
                    $sites->setCurrentSite($site);
                    ElasticsearchRecord::$siteId = $site->id;

                    /** @noinspection NullPointerExceptionInspection NPE cannot happen here */
                    $blacklistedSections = ElasticsearchPlugin::getInstance()->getSettings()->blacklistedSections[$site->id];

                    $countEntries = (int)Entry::find()
                        ->status(Entry::STATUS_ENABLED)
                        ->where(['not in', 'sectionId', $blacklistedSections])
                        ->count();
                    $countEsRecords = (int)ElasticsearchRecord::find()->count();

                    Craft::debug("Active entry count for site #{$site->id}: {$countEntries}", __METHOD__);
                    Craft::debug("Elasticsearch record count for site #{$site->id}: {$countEsRecords}", __METHOD__);

                    if ($countEntries !== $countEsRecords) {
                        Craft::debug('Elasticsearch reindex needed!', __METHOD__);
                        return false;
                    }
                }
            }

            return true;
        }, 300);

        return $inSync;
    }


    // Index Management - Methods related to creating/removing indexes
    // =========================================================================

    /**
     * Create an Elasticsearch index for the giver site
     *
     * @param int $siteId
     *
     * @throws InvalidConfigException If the `$siteId` isn't set
     * @throws Exception If an error in the communication with the Elasticsearch instance occurs
     */
    public function createSiteIndex(int $siteId)
    {
        Craft::info("Creating an Elasticsearch index for the site #{$siteId}", __METHOD__);

        ElasticsearchRecord::$siteId = $siteId;
        ElasticsearchRecord::createIndex();
    }

    /**
     * Remove the Elasticsearch index for the given site
     *
     * @param int $siteId
     *
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public function removeSiteIndex(int $siteId)
    {
        Craft::info("Removing the Elasticsearch index for the site #{$siteId}", __METHOD__);
        ElasticsearchRecord::$siteId = $siteId;
        ElasticsearchRecord::deleteIndex();
    }

    /**
     * Re-create the Elasticsearch index of each of the site matching any of `$siteIds`
     *
     * @param int[] $siteIds
     *
     * @throws InvalidConfigException If the `$siteId` isn't set
     * @throws Exception If an error in the communication with the Elasticsearch instance occurs
     */
    public function recreateSiteIndex(int ...$siteIds)
    {
        foreach ($siteIds as $siteId) {
            $this->removeSiteIndex($siteId);
            $this->createSiteIndex($siteId);
        }
    }


    // Index Manipulation - Methods related to adding to / removing from the index
    // =========================================================================

    /**
     * Index the given `$entry` into Elasticsearch
     *
     * @param Entry $entry
     *
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     *
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

        $esRecord->content = base64_encode(trim($html));

        try {
            $isSuccessfullySaved = $esRecord->save();
        } catch (\Exception $e) {
            throw new Exception('Could not save elasticsearch record');
        }

        if (!$isSuccessfullySaved) {
            throw new Exception('Could not save elasticsearch record', $esRecord->errors);
        }

        return null;
    }

    /**
     * Removes an entry from  the Elasticsearch index
     *
     * @param Entry $entry
     *
     * @return int
     * @throws Exception
     */
    public function deleteEntry(Entry $entry): int
    {
        Craft::info("Deleting entry #{$entry->id}: {$entry->url}", __METHOD__);

        ElasticsearchRecord::$siteId = $entry->siteId;

        return ElasticsearchRecord::deleteAll(['_id' => $entry->id]);
    }

    /**
     * Recreate the Elasticsearch index of all sites and and reindex their entries
     *
     * @throws IndexEntryException If reindexing an entry fails for some reason.
     */
    public function reindexAll()
    {
        $sites = Craft::$app->getSites();

        foreach ($sites->getAllSites() as $site) {
            $this->reindexBySiteId($site->id);
        }

        Craft::$app->getCache()->delete(self::getSyncCachekey()); // Invalidate cache
    }

    /**
     * Recreate the Elasticsearch index for the site having the given `$siteId` and reindex its entries
     *
     * @param int $siteId
     *
     * @throws IndexEntryException If reindexing the entry fails for some reason.
     */
    public function reindexBySiteId(int $siteId)
    {
        try {
            $this->recreateSiteIndex($siteId);
        } catch (\Exception $e) {
            throw new IndexEntryException(Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'Cannot recreate the Elasticsearch index for site #{siteId}: {previousExceptionMessage}',
                ['siteId' => $siteId, 'previousExceptionMessage' => $e->getMessage()]
            ));
        }

        /** @var Entry[] $entries */
        $entries = Entry::findAll([
            'siteId' => $siteId,
        ]);

        foreach ($entries as $entry) {
            if ($entry->enabled) {
                try {
                    $this->indexEntry($entry);
                } catch (\Exception $e) {
                    Craft::error("Error while re-indexing entry {$entry->url}: {$e->getMessage()}", __METHOD__);
                    Craft::error(VarDumper::dumpAsString($e), __METHOD__);

                    throw new IndexEntryException(Craft::t(
                        ElasticsearchPlugin::TRANSLATION_CATEGORY,
                        'Cannot reindex entry {entryUrl}: {previousExceptionMessage}',
                        ['entryUrl' => $entry->url, 'previousExceptionMessage' => $e->getMessage()]
                    ));
                }
            }
        }
    }

    /**
     * Execute the given `$query` in the Elasticsearch index
     *
     * @param string   $query  String to search for
     * @param int|null $siteId Site id to make the search
     *
     * @return ElasticsearchRecord[]
     * @throws IndexEntryException
     */
    public function search($query, $siteId = null): array
    {
        if (null === $query) {
            return [];
        }

        if ($siteId === null) {
            try {
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
            } catch (SiteNotFoundException $e) {
                throw new IndexEntryException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'Cannot fetch the id of the current site. Please make sure at least one site is enabled.'
                ));
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
            throw new IndexEntryException(
                Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'An error occurred while running the "{searchQuery}" search query on Elasticsearch instance: {previousExceptionMessage}',
                    ['previousExceptionMessage' => $e->getMessage(), 'searchQuery' => $query]
                ),
                0,
                $e
            );
        }
    }

    protected static function getSyncCachekey(): string
    {
        return self::class.'_isSync';
    }

    /**
     * @param Entry $entry
     *
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
     *
     * @return string
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
            $templateName = $sectionSiteSettings[$entry->siteId]->template;

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
                throw new IndexEntryException(
                    Craft::t(
                        ElasticsearchPlugin::TRANSLATION_CATEGORY,
                        'The entry #{entryId} uses an invalid Twig template: {twigTemplateName}',
                        ['entryId' => $entry->id, 'twigTemplateName' => $templateName]
                    ),
                    0,
                    $e
                );
            } catch (\yii\base\Exception $e) {
                throw new IndexEntryException(
                    Craft::t(
                        ElasticsearchPlugin::TRANSLATION_CATEGORY,
                        'An error occurred while rendering the {twigTemplateName} Twig template: {previousExceptionMessage}',
                        ['twigTemplateName' => $templateName, 'previousExceptionMessage' => $e->getMessage()]
                    ),
                    0,
                    $e
                );
            }
        } catch (InvalidConfigException $e) {
            throw new IndexEntryException(
                Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'The entry #{entryId} has an incorrect section id: #{sectionId}',
                    ['entryId' => $entry->id, 'sectionId' => $entry->sectionId]
                ),
                0,
                $e
            );
        }
    }

    /**
     * @param $html
     *
     * @return string
     */
    protected function extractIndexablePartFromEntryContent(string $html): string
    {
        /** @noinspection NullPointerExceptionInspection NPE cannot happen here. */
        if ($callback = ElasticsearchPlugin::getInstance()->getSettings()->contentExtractorCallback) {
            return $callback($html);
        }

        if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $html, $body)) {
            $html = '<!DOCTYPE html>'.trim($body[1]);
        }

        return $html;
    }

    /**
     * Get the reason why an entry should NOT be reindex.
     *
     * @param Entry $entry The entry to consider for reindexing
     *
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
        $blacklist = ElasticsearchPlugin::getInstance()->getSettings()->blacklistedSections;
        if (isset($blacklist[$entry->siteId]) && in_array($entry->sectionId, $blacklist[$entry->siteId], false)) {
            $message = "Not indexing entry #{$entry->id} since it's in a blacklisted section.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        return null;
    }

    /**
     * @param int[]|null $siteIds An array containing the ids of sites to be or
     *                            reindexed, or `null` to reindex all sites.
     *
     * @return array An array of entry descriptors. An entry descriptor is an
     *               associative array with the `entryId` and `siteId` keys.
     */
    public function getEnabledEntries($siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $entryQuery = (new Query())
            ->select(['{{%elements}}.id entryId', 'siteId'])
            ->from('{{%elements}}')
            ->join('inner join', '{{%elements_sites}}', '{{%elements}}.id = elementId')
            ->where([
                '{{%elements}}.type'    => Entry::class,
                '{{%elements}}.enabled' => true,
                'siteId'                => $siteIds,
            ]);

        return $entryQuery->all();
    }

    /**
     * Create an empty Elasticsearch index for all sites. Existing indexes will be deleted and recreated.
     *
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
}
