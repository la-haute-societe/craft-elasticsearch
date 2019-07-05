<?php
/**
 * @link http://www.lahautesociete.com
 * @copyright Copyright (c) 2019 La Haute Société
 */

namespace lhs\elasticsearch\migrations;

use craft\db\Migration;
use lhs\elasticsearch\Elasticsearch;

/**
 * UpdateSchema class
 *
 * @author albanjubert
 **/
class m190602_000000_recreate_indexes extends Migration
{
    public function safeUp()
    {
        // Indexes need to be updated to take new fields in consideration
        $this->_rebuildElasticsearchIndexes();
    }

    public function safeDown()
    {
        $this->_rebuildElasticsearchIndexes();
    }

    private function _rebuildElasticsearchIndexes()
    {
        $elasticsearch = Elasticsearch::getInstance();
        $elasticsearch->service->recreateIndexesForAllSites();
        $elasticsearch->reindexQueueManagementService->enqueueReindexJobs($elasticsearch->service->getEnabledEntries());
        if ($elasticsearch->isCommerceEnabled()) {
            $elasticsearch->reindexQueueManagementService->enqueueReindexJobs($elasticsearch->service->getEnabledProducts());
        }
    }
}
