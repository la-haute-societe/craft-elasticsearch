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
use craft\web\Controller;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\exceptions\IndexElementException;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;

/**
 * Controller used only when reindexing using the command line utility.
 * All actions can be accessed anonymously but only from allowed IP addresses or hosts.
 * @see the `allowedIPs` and `allowedHosts` settings
 */
class SiteController extends Controller
{
    protected $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * @return \yii\web\Response
     * @throws ForbiddenHttpException If the requesting IP or hostname is not
     *                                whitelisted.
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function actionReindexElement(): \yii\web\Response
    {
        if (!$this->checkAccess()) {
            throw new ForbiddenHttpException('You are not allowed to view this resource');
        }

        try {
            $elementId = Craft::$app->getRequest()->getParam('elementId');
            $siteId = Craft::$app->getRequest()->getParam('siteId');
            $type = Craft::$app->getRequest()->getParam('type');

            $element = $type === 'craft\\commerce\\elements\\Product' ? craft\commerce\Plugin::getInstance()->getProducts()->getProductById($elementId, $siteId) : Craft::$app->getEntries()->getEntryById($elementId, $siteId);

            if ($element === null) {
                throw new IndexElementException(Craft::t(
                    Elasticsearch::TRANSLATION_CATEGORY,
                    'No such element (element #{elementId} / site #{siteId}',
                    ['elementId' => $elementId, 'siteId' => $siteId]
                ));
            }

            $reason = Elasticsearch::getInstance()->service->indexElement($element);

            if ($reason !== null) {
                return $this->asJson([
                    'success' => true,
                    'skipped' => true,
                    'reason'  => $reason,
                ]);
            }
        } catch (\Exception $e) {
            Craft::$app->getResponse()->statusCode = 500;

            $previousException = $e->getPrevious();
            return $this->asJson([
                'success'         => false,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
                'previousMessage' => $previousException ? $previousException->getMessage() : null,
                'previousTrace'   => $previousException ? $previousException->getTraceAsString() : null,
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * @return \yii\web\Response
     * @throws ForbiddenHttpException If the requesting IP or hostname is not whitelisted.
     */
    public function actionGetAllElements(): \yii\web\Response
    {
        if (!$this->checkAccess()) {
            throw new ForbiddenHttpException('You are not allowed to view this resource');
        }

        $elasticsearch = Elasticsearch::getInstance();

        $entries = $elasticsearch->service->getEnabledEntries();
        if ($elasticsearch->isCommerceEnabled()) {
            $products = $elasticsearch->service->getEnabledProducts();
            $entries = ArrayHelper::merge($entries, $products);
        }
        return $this->asJson(['entries' => $entries]);
    }

    /**
     * Checks if current user is allowed to access this utility controller
     *
     * @return bool A boolean indicating whether the request is allowed
     */
    protected function checkAccess(): bool
    {
        $elasticsearchPlugin = Elasticsearch::getInstance();
        assert($elasticsearchPlugin !== null, "Elasticsearch::getInstance() should always return the plugin instance when called from the plugin's code.");

        $allowedIPs = $elasticsearchPlugin->getSettings()->allowedIPs;
        $allowedHosts = $elasticsearchPlugin->getSettings()->allowedHosts;
        $ip = Craft::$app->getRequest()->getUserIP();

        foreach ($allowedIPs as $filter) {
            if ($filter === '*' || $filter === $ip || (($pos = strpos($filter, '*')) !== false && strncmp($ip, $filter, $pos) === 0)) {
                return true;
            }
        }

        foreach ($allowedHosts as $hostname) {
            $filter = gethostbyname($hostname);
            Craft::warning(sprintf(
                'Comparing the IP of %s (%s) and %s.',
                $hostname,
                $filter,
                $ip
            ));

            if ($filter === $ip) {
                return true;
            }
        }

        Craft::warning('Access to the elasticsearch CLI utility is denied due to IP address restriction. The requesting IP address is ' . $ip, __METHOD__);

        return false;
    }
}
