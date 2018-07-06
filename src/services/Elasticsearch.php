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
use craft\elements\Entry;
use craft\web\Response;
use craft\web\View;
use DateTime;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\records\ElasticsearchRecord;
use yii\elasticsearch\Exception;
use yii\helpers\VarDumper;
use yii\web\ServerErrorHttpException;

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
     * Test the connection to the Elasticsearch server
     *
     * @return boolean `true` if the connection succeeds, `false` otherwise.
     */
    public function testConnection(): bool
    {
        $connection = ElasticsearchPlugin::getConnection();
        try {
            $connection->open();
            return true;
        } catch (\Exception $e) {
            return false;
        }
        finally {
            $connection->close();
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
            if ($this->testConnection() === true) {
                $sites = Craft::$app->getSites();

                foreach ($sites->getAllSites() as $site) {
                    $sites->setCurrentSite($site);

                    ElasticsearchRecord::$siteId = $site->id;
                    $esClass = new ElasticsearchRecord();
                    $countEntries = (int)Entry::find()->status(Entry::STATUS_ENABLED)->count();
                    $countEsRecords = (int)$esClass::find()->count();

                    Craft::debug("Count active entries for site {$site->id}: {$countEntries}", __METHOD__);
                    Craft::debug("Count Elasticsearch records for site {$site->id}: {$countEsRecords}", __METHOD__);

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
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
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
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function recreateSiteIndexes(int ...$siteIds)
    {
        foreach ($siteIds as $siteId) {
            $this->removeSiteIndex($siteId);
            $this->createSiteIndex($siteId);
        }
    }


    // Index Manipulation - Methods related to adding to / removing from the index
    // =========================================================================

    /**
     * Index a given entry into Elasticsearch
     *
     * @param Entry $entry
     *
     * @return bool
     * @throws ServerErrorHttpException
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function indexEntry(Entry $entry)
    {
        if (
            $entry->status !== Entry::STATUS_LIVE ||
            !$entry->enabledForSite ||
            !$entry->hasContent()
        ) {
            return false;
        }

        Craft::info("Indexing entry {$entry->url}", __METHOD__);

        $esRecord = $this->getElasticRecordForEntry($entry);
        $esRecord->title = $entry->title;
        $esRecord->url = $entry->url;

        $html = $this->getEntryIndexableContent($entry);

        $esRecord->content = base64_encode(trim($html));

        if (!$esRecord->save()) {
            throw new Exception('Could not save elasticsearch record', $esRecord->errors);
        }

        return true;
    }

    /**
     * Removes an entry from  the Elasticsearch index
     *
     * @param Entry $entry
     *
     * @return int
     * @throws Exception
     */
    public function deleteEntry(Entry $entry)
    {
        Craft::info("Deleting entry #{$entry->id}: {$entry->url}", __METHOD__);

        ElasticsearchRecord::$siteId = $entry->siteId;

        return ElasticsearchRecord::deleteAll(['_id' => $entry->id]);
    }

    /**
     * Recreate the Elasticsearch index of all sites and and reindex their entries
     */
    public function reindexAll()
    {
        $sites = Craft::$app->getSites();

        foreach ($sites->getAllSites() as $site) {
            $this->reindexBySiteId($site->id);
        }

        Craft::$app->cache->delete(self::getSyncCachekey()); // Invalidate cache
    }

    /**
     * Recreate the Elasticsearch index for the site having the given `$siteId` and reindex its entries
     *
     * @param int $siteId
     *
     * @throws \Exception If reindexing the entry fails for some reason.
     */
    public function reindexBySiteId(int $siteId)
    {
        $this->recreateSiteIndexes($siteId);

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

                    throw $e;
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
     */
    public function search($query, $siteId = null): array
    {
        if (null === $query) {
            return [];
        }

        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        ElasticsearchRecord::$siteId = $siteId;
        $results = ElasticsearchRecord::search($query);
        $output = [];
        foreach ($results as $result) {
            $output[] = [
                'id'         => $result->getPrimaryKey(),
                'title'      => $result->title,
                'url'        => $result->url,
                'score'      => $result->score,
                'highlights' => isset($result->highlight['attachment.content']) ? $result->highlight['attachment.content'] : [],
            ];
        }

        return $output;
    }

    protected static function getSyncCachekey()
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
     * @throws ServerErrorHttpException
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    protected function getEntryIndexableContent(Entry $entry): string
    {
        $sites = Craft::$app->getSites();
        $view = Craft::$app->getView();
        $site = $sites->getSiteById($entry->siteId);

        if (!$site) {
            throw new ServerErrorHttpException('Invalid site ID: '.$entry->siteId);
        }
        $sites->setCurrentSite($site);

        Craft::$app->language = $site->language;

        if (!$entry->postDate) {
            $entry->postDate = new DateTime();
        }

        // Have this entry override any freshly queried entries with the same ID/site ID
        Craft::$app->getElements()->setPlaceholderElement($entry);

        // Switch to site template rendering mode
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        // Disable template caching before rendering.
        // Without this, templates using the `{% cache %}` Twig tag may have a strange behavior.
        $craftGeneralConfig = Craft::$app->getConfig()->getGeneral();
        $craftGeneralConfig->enableTemplateCaching = false;

        $sectionSiteSettings = $entry->getSection()->getSiteSettings();
        $templateName = $sectionSiteSettings[$entry->siteId]->template;
        $html = trim($view->renderTemplate($templateName, [
            'entry' => $entry,
        ]));

        // Re-enable template caching. On est pas des bêtes !
        $craftGeneralConfig->enableTemplateCaching = false;
        // Restore template rendering mode
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $this->extractIndexablePartFromEntryContent($html);

        return $html;
    }

    /**
     * @param $html
     *
     * @return string
     */
    protected function extractIndexablePartFromEntryContent(string $html): string
    {
        if ($callback = ElasticsearchPlugin::getInstance()->settings->contentExtractorCallback) {
            return call_user_func($callback, $html);
        }

        if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $html, $body)) {
            $html = '<!DOCTYPE html>'.trim($body[1]);
        }

        return $html;
    }
}
