<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\console\controllers;

use craft\records\Site;
use lhs\elasticsearch\Elasticsearch;
use yii\console\Controller;

/**
 * Allow various operation for elastisearch index
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
     * Reindex Craft entries into elasticsearch
     *
     * @return mixed
     */
    public function actionReindexAll()
    {
        return ElasticSearch::getInstance()->service->reindexAll();
    }

    /**
     * Remove & recreate an empty index for all sites
     */
    public function actionRecreateEmptyIndexes()
    {
        $siteIds = Site::find()->select('id')->column();

        ElasticSearch::getInstance()->service->recreateSiteIndexes(...$siteIds);
    }
}
