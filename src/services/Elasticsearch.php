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
use craft\records\Element;
use craft\web\View;
use DateTime;
use lhs\elasticsearch\Elasticsearch as Es;
use lhs\elasticsearch\jobs\IndexElement;
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
    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     Elasticsearch::$plugin->elasticsearch->testConnection()
     *
     * @return boolean
     */
    public function testConnection()
    {
        $connection = Es::$plugin->connection;
        try {
            $connection->open();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Recreate all Elasticsearch indexes and reindex every Craft entries
     */
    public function reindexAll()
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            Craft::$app->getSites()->setCurrentSite($site);
            $this->reindexBySiteId($site->id);
        }
    }

    /**
     * Recreate Elasticsearch index for a given siteId
     * @param int $siteId
     * @throws Exception
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
                Craft::$app->queue->push(new IndexElement([
                    'siteId' => $siteId,
                    'elementId' => $element->id,
                ]));
            }
        }
    }

    public function search($query)
    {

    }

    /**
     * Index a given entry into Elasticsearch
     * @param Entry $entry
     * @throws Exception
     * @throws ServerErrorHttpException
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function indexEntry(Entry $entry)
    {
        if ($entry->status == Entry::STATUS_LIVE && $entry->enabledForSite && $entry->hasContent()) {
            Craft::info(
                Craft::t(
                    'elasticsearch',
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

            Craft::$app->view->getTwig()->disableStrictVariables();

            $html = trim(Craft::$app->view->renderTemplate($sectionSiteSettings[$entry->siteId]->template, [
                'entry' => $entry
            ]));

            $body = null;
            if (preg_match(Es::$plugin->settings->content_pattern, $html, $body)) {
                $html = '<!DOCTYPE html>' . trim($body[1]);
            }

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
                'elasticsearch',
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
}
