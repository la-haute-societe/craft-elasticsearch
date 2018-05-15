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
        return Elasticsearch::$plugin->elasticsearch->reindexAll();
    }
}
