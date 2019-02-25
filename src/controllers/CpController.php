<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\records\Site;
use craft\web\Controller;
use craft\web\Request;
use lhs\elasticsearch\Elasticsearch;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\web\Response;

/**
 * Control Panel controller
 */
class CpController extends Controller
{
    public $allowAnonymous = ['testConnection', 'reindexPerformAction'];

    /**
     * Test the elasticsearch connection
     *
     * @return Response
     * @throws \yii\web\ForbiddenHttpException If the user doesn't have the utility:refresh-elasticsearch-index permission
     */
    public function actionTestConnection(): Response
    {
        $this->requirePermission('utility:refresh-elasticsearch-index');

        $elasticsearchPlugin = Elasticsearch::getInstance();
        assert($elasticsearchPlugin !== null, "Elasticsearch::getInstance() should always return the plugin instance when called from the plugin's code.");

        $settings = $elasticsearchPlugin->getSettings();

        if ($elasticsearchPlugin->service->testConnection() === true) {
            Craft::$app->session->setNotice(Craft::t(
                Elasticsearch::TRANSLATION_CATEGORY,
                'Successfully connected to {elasticsearchEndpoint}',
                ['elasticsearchEndpoint' => $settings->elasticsearchEndpoint]
            ));
        } else {
            Craft::$app->session->setError(Craft::t(
                Elasticsearch::TRANSLATION_CATEGORY,
                'Could not establish connection with {elasticsearchEndpoint}',
                ['elasticsearchEndpoint' => $settings->elasticsearchEndpoint]
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
        $this->requirePermission('utility:refresh-elasticsearch-index');

        $request = Craft::$app->getRequest();
        $params = $request->getRequiredBodyParam('params');

        // Return the ids of entries to process
        if (!empty($params['start'])) {

            try {
                $siteIds = $this->getSiteIds($request);
                Elasticsearch::getInstance()->service->recreateSiteIndex(...$siteIds);
            } catch (\Exception $e) {
                return $this->asErrorJson($e->getMessage());
            }

            return $this->getReindexQueue($siteIds);
        }

        // Process the given element
        $reason = $this->reindexElement();

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
        $elements = Elasticsearch::getInstance()->service->getEnabledEntries($siteIds);
        if (Elasticsearch::getInstance()->isCommerceEnabled()) {
            $elements = ArrayHelper::merge($elements, Elasticsearch::getInstance()->service->getEnabledProducts());
        }
        // Re-format elements to keep the JS part as close as possible to Craft SearchIndexUtility's
        array_walk($elements, function (&$element) {
            $element = ['params' => $element];
        });

        return $this->asJson([
            'entries' => [$elements],
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
    protected function reindexElement()
    {
        $request = Craft::$app->getRequest();

        $elementId = $request->getRequiredBodyParam('params.elementId');
        $siteId = $request->getRequiredBodyParam('params.siteId');
        $type = $request->getRequiredBodyParam('params.type');
        switch ($type) {
            case Product::class:
                $element = Product::find()->id($elementId)->siteId($siteId)->one();
                break;
            default:
                $element = Entry::find()->id($elementId)->siteId($siteId)->one();
        }
        try {
            return Elasticsearch::getInstance()->service->indexElement($element);
        } catch (\Exception $e) {
            Craft::error("Error while re-indexing element {$element->url}: {$e->getMessage()}", __METHOD__);
            Craft::error(VarDumper::dumpAsString($e), __METHOD__);

            throw $e;
        }
    }
}
