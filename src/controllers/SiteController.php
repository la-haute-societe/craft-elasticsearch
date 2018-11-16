<?php
/**
 * @author Yohann Bianchi<yohann.b@lahautesociete.com>
 * @date   10/07/2018 11:21
 */

namespace lhs\elasticsearch\controllers;


use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\exceptions\IndexEntryException;
use yii\web\ForbiddenHttpException;

class SiteController extends Controller
{
    protected $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * @return \yii\web\Response
     * @throws ForbiddenHttpException If the requesting IP or hostname is not
     *                                whitelisted.
     */
    public function actionReindexEntry(): \yii\web\Response
    {
        if (!$this->checkAccess()) {
            throw new ForbiddenHttpException('You are not allowed to view this resource');
        }

        try {
            $entryId = Craft::$app->getRequest()->getParam('entryId');
            $siteId = Craft::$app->getRequest()->getParam('siteId');
            $entry = Entry::find()->id($entryId)->siteId($siteId)->one();

            if ($entry === null) {
                throw new IndexEntryException(Craft::t(
                    Elasticsearch::TRANSLATION_CATEGORY,
                    'No such entry (entry #{entryId} / site #{siteId}',
                    ['entryId' => $entryId, 'siteId' => $siteId]
                ));
            }

            $reason = Elasticsearch::getInstance()->service->indexEntry($entry);

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
    public function actionGetAllEntries(): \yii\web\Response
    {
        if (!$this->checkAccess()) {
            throw new ForbiddenHttpException('You are not allowed to view this resource');
        }

        $entries = Elasticsearch::getInstance()->service->getEnabledEntries();

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
