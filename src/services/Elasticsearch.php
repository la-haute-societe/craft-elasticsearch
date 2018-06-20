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
    // ElasticSearch / Craft communication
    // =========================================================================

    /**
     * Test the connection to the ElasticSearch server
     * @return boolean `true` if the connection succeeds, `false` otherwise.
     */
    public function testConnection()
    {
        $connection = Craft::$app->elasticsearch;
        try {
            $connection->open();
            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            $connection->close();
        }
    }

    /**
     * Check whether or not ElasticSearch is in sync with Craft
     * @return boolean `true` if ElasticSearch is in sync with Craft, `false` otherwise.
     */
    public function isIndexInSync()
    {

        $inSync = Craft::$app->cache->getOrSet(self::getSyncCachekey(), function () {
            $inSync = true;
            if ($this->testConnection() === true) {
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    Craft::$app->getSites()->setCurrentSite($site);
                    $esClass = new ElasticsearchRecord();
                    $esClass::$siteId = $site->id;
                    $countEntries = (int)Entry::find()->status(Entry::STATUS_ENABLED)->count();
                    $countEsRecords = (int)$esClass::find()->count();
                    Craft::debug('Count active entries for site {$site->id}: {$countEntries}', __METHOD__);
                    Craft::debug('Count Elasticsearch records for site {$site->id}: {$countEsRecords}', __METHOD__);
                    if ($countEntries !== $countEsRecords) {
                        Craft::debug('Elasticsearch reindex needed!', __METHOD__);
                        $inSync = false;
                    }
                }
            }
            return $inSync;
        }, 300);
        return $inSync;
    }


    // Index Management - Methods related to creating/removing indexes
    // =========================================================================

    public function createSiteIndex(int $siteId)
    {
        Craft::info(Craft::t(
            ElasticsearchPlugin::TRANSLATION_CATEGORY,
            'Creating an ElasticSearch index for the site #{siteId}',
            ['siteId' => $siteId]
        ), __METHOD__);

        $esRecord = new ElasticsearchRecord();
        $esRecord::$siteId = $siteId;
        $esRecord::createIndex();
    }

    /**
     * Remove the ElasticSearch index for the given site
     * @param int $siteId
     */
    public function removeSiteIndex($siteId)
    {
        Craft::info(Craft::t(
            ElasticsearchPlugin::TRANSLATION_CATEGORY,
            'Removing the ElasticSearch index for the site #{siteId}',
            ['siteId' => $siteId]
        ), __METHOD__);

        $esClass = new ElasticsearchRecord();
        $esClass::$siteId = $siteId;
        $esClass::deleteIndex();
    }

    /**
     * Re-create the ElasticSearch index of each of the site matching any of `$siteIds`
     * @param int[] $siteIds
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
     * @param Entry $entry
     * @throws ServerErrorHttpException
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function indexEntry(Entry $entry)
    {
        if ($entry->status === Entry::STATUS_LIVE && $entry->enabledForSite && $entry->hasContent()) {
            Craft::info(
                Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'Indexing entry {url}',
                    ['url' => $entry->url]
                ),
                __METHOD__
            );

            $esClass = new ElasticsearchRecord();
            $esClass::$siteId = $entry->siteId;
            $esRecord = $esClass::findOne($entry->id);

            if (empty($esRecord)) {
                $esRecord = new $esClass();
                $esClass::$siteId = $entry->siteId;
                $esRecord->setPrimaryKey($entry->id);
            }
            $esRecord->title = $entry->title;
            $esRecord->url = $entry->url;

            Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            $sectionSiteSettings = $entry->getSection()->getSiteSettings();

            $site = Craft::$app->getSites()->getSiteById($entry->siteId);

            if (!$site) {
                throw new ServerErrorHttpException('Invalid site ID: ' . $entry->siteId);
            }
            Craft::$app->getSites()->setCurrentSite($site);

            Craft::$app->language = $site->language;

            if (!$entry->postDate) {
                $entry->postDate = new DateTime();
            }

            // Have this entry override any freshly queried entries with the same ID/site ID
            Craft::$app->getElements()->setPlaceholderElement($entry);

            // Backup & disable Twig cache before rendering.
            // Without this, templates using the `{% cache %}` Twig tag may have a strange behavior.
            $twigCache = Craft::$app->getView()->getTwig()->getCache();
            Craft::$app->getView()->getTwig()->setCache(false);

            $pathInfo = Craft::$app->getRequest()->getPathInfo();
            Craft::$app->getRequest()->setPathInfo($entry->url);

            $templateName = $sectionSiteSettings[$entry->siteId]->template;
            $html = trim(Craft::$app->view->renderTemplate($templateName, [
                'entry' => $entry,
            ]));

            // Restore Twig cache configuration. On est pas des bêtes !
            Craft::$app->getView()->getTwig()->setCache($twigCache);

            $body = null;
            if (preg_match(ElasticSearchPlugin::getInstance()->settings->content_pattern, $html, $body)) {
                $html = '<!DOCTYPE html>' . trim($body[1]);
            }

            Craft::$app->getResponse()->format = Response::FORMAT_HTML;

            $esRecord->content = base64_encode(trim($html));

            Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_CP);


            if (!$esRecord->save()) {
                throw new Exception("Could not save elasticsearch record", $esRecord->errors);
            }
        }
    }

    public function deleteEntry(Entry $entry)
    {
        Craft::info(
            Craft::t(
                ElasticsearchPlugin::TRANSLATION_CATEGORY,
                'Deleting entry {url}',
                ['url' => $entry->url]
            ),
            __METHOD__
        );

        $esClass = new ElasticsearchRecord();
        $esClass::$siteId = $entry->siteId;
        $esRecord = $esClass::findOne($entry->id);
        if ($esRecord) {
            $esRecord->delete();
        }
    }

    /**
     * Recreate the ElasticSearch index of all sites and and reindex their entries
     */
    public function reindexAll()
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            Craft::$app->getSites()->setCurrentSite($site);
            $this->reindexBySiteId($site->id);
        }
        Craft::$app->cache->delete(self::getSyncCachekey()); // Invalidate cache
    }

    /**
     * Recreate the ElasticSearch index for the site having the given `$siteId` and reindex its entries
     * @param int $siteId
     * @throws ServerErrorHttpException
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function reindexBySiteId($siteId)
    {
        $esClass = new ElasticsearchRecord();
        $esClass::$siteId = $siteId;
        $esClass::deleteIndex();
        /** @var Entry $element */
        foreach (Entry::find()->each() as $element) {
            if ($element->enabled) {
                $this->indexEntry($element);
            }
        }
    }

    /**
     * Execute the given `$query` in the ElasticSearch index
     * @param string   $query  String to search for
     * @param int|null $siteId Site id to make the search
     * @return array|ElasticsearchRecord[]
     */
    public function search($query, $siteId = null)
    {
        if (is_null($query)) {
            return [];
        }
        if (is_null($siteId)) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }
        $esClass = new ElasticsearchRecord();
        $esClass::$siteId = $siteId;
        $results = $esClass::search($query);
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

    private static function getSyncCachekey()
    {
        return self::class . '_isSync';
    }
}
