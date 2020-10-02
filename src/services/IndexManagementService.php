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
use craft\records\Site;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\events\ErrorEvent;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\records\ElasticsearchRecord;

/**
 */
class IndexManagementService extends Component
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    /**
     * Create an Elasticsearch index for the giver site
     * @param int $siteId
     */
    public function createSiteIndex(int $siteId): void
    {
        Craft::info("Creating an Elasticsearch index for the site #{$siteId}", __METHOD__);

        ElasticsearchRecord::$siteId = $siteId;
        $esRecord = new ElasticsearchRecord(); // Needed to trigger according event
        $esRecord->createESIndex();
    }

    /**
     * Remove the Elasticsearch index for the given site
     * @noinspection PhpDocMissingThrowsInspection Cannot happen since we DO set the siteId property
     * @param int $siteId
     */
    public function removeSiteIndex(int $siteId): void
    {
        Craft::info("Removing the Elasticsearch index for the site #{$siteId}", __METHOD__);
        ElasticsearchRecord::$siteId = $siteId;
        /** @noinspection PhpUnhandledExceptionInspection Cannot happen since we DO set the siteId property */
        ElasticsearchRecord::deleteIndex();
    }

    /**
     * Re-create the Elasticsearch index of sites matching any of `$siteIds`
     * @param int[] $siteIds
     * @noinspection PhpRedundantCatchClauseInspection Exception may be thrown by \yii\elasticsearch\Connection::delete()
     */
    public function recreateSiteIndex(int ...$siteIds): void
    {
        foreach ($siteIds as $siteId) {
            try {
                $this->removeSiteIndex($siteId);
                $this->createSiteIndex($siteId);
            } catch (\yii\elasticsearch\Exception $e) {
                $this->triggerErrorEvent($e);
            }
        }
    }

    /**
     * Create an empty Elasticsearch index for all sites. Existing indexes will be deleted and recreated.
     * @throws IndexElementException If the Elasticsearch index of a site cannot be recreated
     */
    public function recreateIndexesForAllSites(): void
    {
        $siteIds = Site::find()->select('id')->column();

        if (!empty($siteIds)) {
            try {
                $this->recreateSiteIndex(...$siteIds);
            } catch (\Exception $e) {
                throw new IndexElementException(
                    Craft::t(
                        ElasticsearchPlugin::PLUGIN_HANDLE,
                        'Cannot recreate empty indexes for all sites'
                    ), 0, $e
                );
            }
        }

        Craft::$app->getCache()->delete(ElasticsearchService::getSyncCachekey()); // Invalidate cache
    }

    protected function triggerErrorEvent(\yii\elasticsearch\Exception $e): void
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
