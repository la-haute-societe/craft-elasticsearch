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
use craft\web\Request;
use lhs\elasticsearch\Elasticsearch;
use yii\helpers\VarDumper;
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
class CpController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Test the elasticsearch connection
     *
     * @return Response
     */
    public function actionTestConnection(): Response
    {
        $settings = Elasticsearch::getInstance()->getSettings();

        if (Elasticsearch::getInstance()->service->testConnection() === true) {
            Craft::$app->session->setNotice(Craft::t(
                'elasticsearch',
                'Successfully connected to {http_address}',
                ['http_address' => $settings->http_address]
            ));
        } else {
            Craft::$app->session->setError(Craft::t(
                'elasticsearch',
                'Could not establish connection with {http_address}',
                ['http_address' => $settings->http_address]
            ));
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/elasticsearch-utilities'));
    }

    /**
     * Reindex Craft entries into Elasticsearch (called from utility panel)
     *
     * @return Response
     * @throws \Exception If reindexing an entry fails
     * @throws \yii\web\BadRequestHttpException if the request body is missing a `params` property
     * @throws \yii\web\ForbiddenHttpException if the user doesn't have access to the Elasticsearch utility
     */
    public function actionReindexPerformAction(): Response
    {
        $this->requirePermission('elasticsearch:reindex');

        $request = Craft::$app->getRequest();
        $params = $request->getRequiredBodyParam('params');

        // Return the ids of entries to process
        if (!empty($params['start'])) {
            $siteIds = $this->getSiteIds($request);
            Elasticsearch::getInstance()->service->recreateSiteIndex(...$siteIds);

            return $this->getReindexQueue($siteIds);
        }

        // Process the given element
        $reason = $this->reindexEntry();

        if ($reason !== null) {
            return $this->asJson([
                'success' => true,
                'skipped' => true,
                'reason'  => $reason,
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * @param int[] $siteIds The numeric ids of sites to be re-indexed
     *
     * @return Response
     */
    protected function getReindexQueue(array $siteIds): Response
    {
        $entries = Elasticsearch::getInstance()->service->getEnabledEntries($siteIds);

        // Re-format entries to keep the JS part as close as possible to Craft SearchIndexUtility's
        array_walk($entries, function(&$entry) {
            $entry = ['params' => $entry];
        });

        return $this->asJson([
            'entries' => [$entries],
        ]);
    }

    /**
     * When using Garnish's CheckboxSelect component, the field value is "*" when the all checkbox is selected.
     * This value gets passed to the controller.
     *
     * This methods converts the "*" value into an array containing the id of all sites.
     *
     * @param Request $request
     *
     * @return int[]
     */
    protected function getSiteIds(Request $request): array
    {
        $siteIds = $request->getParam('params.sites', '*');

        if ($siteIds === '*') {
            $siteIds = Site::find()->select('id')->column();
        }

        return $siteIds;
    }

    /**
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     * @throws \Exception If reindexing the entry fails for some reason.
     * @throws \yii\web\BadRequestHttpException if the request body is missing a `params` property
     */
    protected function reindexEntry()
    {
        $request = Craft::$app->getRequest();

        $entryId = $request->getRequiredBodyParam('params.entryId');
        $siteId = $request->getRequiredBodyParam('params.siteId');
        $entry = Entry::find()->id($entryId)->siteId($siteId)->one();

        try {
            return Elasticsearch::getInstance()->service->indexEntry($entry);
        } catch (\Exception $e) {
            Craft::error("Error while re-indexing entry {$entry->url}: {$e->getMessage()}", __METHOD__);
            Craft::error(VarDumper::dumpAsString($e), __METHOD__);

            throw $e;
        }
    }
}
