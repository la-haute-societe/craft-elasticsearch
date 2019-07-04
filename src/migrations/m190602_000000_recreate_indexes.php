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
        Elasticsearch::getInstance()->service->recreateIndexesForAllSites();
    }
}
