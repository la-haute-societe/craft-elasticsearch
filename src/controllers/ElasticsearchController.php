<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\records\Site;
use craft\web\Controller;
use lhs\elasticsearch\Elasticsearch;
use yii\base\Exception;
use yii\web\Response;

/**
 * Elasticsearch Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 */
class ElasticsearchController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Test the elasticsearch connection
     *
     * @return mixed
     */
    public function actionTestConnection()
    {
        if (ElasticSearch::getInstance()->service->testConnection() === true) {
            Craft::$app->session->setNotice(Craft::t(
                'elasticsearch',
                'Successfully connected to {http_address}',
                ['http_address' => $this->module->settings->http_address]
            ));
        } else {
            Craft::$app->session->setError(Craft::t(
                'elasticsearch',
                'Could not establish connection with {http_address}',
                ['http_address' => $this->module->settings->http_address]
            ));
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/elasticsearch-utilities'));
    }

    /**
     * Reindex Craft entries into ElasticSearch (called from utility panel)
     *
     * @return Response
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\web\BadRequestHttpException if the request body is missing a `params` property
     * @throws \yii\web\ForbiddenHttpException if the user doesn't have access to the ElasticSearch utility
     */
    public function actionReindexPerformAction(): Response
    {
        $this->requirePermission('elasticsearch:reindex');

        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');

        // Return the ids of entries to process
        if (!empty($params['start'])) {
            $siteIds = Craft::$app->getRequest()->getParam('params.sites', '*');

            if ($siteIds === '*') {
                $siteIds = Site::find()->select('id')->column();
            }

            ElasticSearch::getInstance()->service->recreateSiteIndexes(...$siteIds);

            return $this->getReindexQueue($siteIds);
        }

        $entryId = Craft::$app->getRequest()->getRequiredBodyParam('params.entryId');
        $siteId = Craft::$app->getRequest()->getRequiredBodyParam('params.siteId');
        $entry = Entry::find()->id($entryId)->siteId($siteId)->one();

        try {
            ElasticSearch::getInstance()->service->indexEntry($entry);
        } catch (Exception $e) {
            Craft::error(
                Craft::t(
                    Elasticsearch::TRANSLATION_CATEGORY,
                    'Error while re-indexing entry {url}',
                    ['url' => $entry->url]
                ),
                __METHOD__
            );

            throw $e;
        }

        // Process the given element
        return $this->asJson(['success' => true]);
    }

    /**
     * @param int[] $siteIds The numeric ids of sites to be re-indexed
     * @return Response
     */
    protected function getReindexQueue(array $siteIds): Response
    {
        $entryQuery = (new Query())
            ->select(['elements.id entryId', 'siteId'])
            ->from('{{%elements}}')
            ->join('inner join', 'elements_sites', 'elements.id = elementId')
            ->where([
                'elements.type' => 'craft\\elements\\Entry',
                'siteId'        => $siteIds,
            ]);

        $entries = $entryQuery->all();

        // Re-format entries to keep the JS part as close as possible to Craft SearchIndexUtility's
        array_walk($entries, function (&$entry) {
            $entry = ['params' => $entry];
        });

        return $this->asJson([
            'entries' => [$entries],
        ]);
    }
}
