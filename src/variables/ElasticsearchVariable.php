<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\variables;

use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\records\ElasticsearchRecord;

/**
 * Elasticsearch Variable
 *
 * Craft allows plugins to provide their own template variables, accessible from
 * the {{ craft }} global variable (e.g. {{ craft.elasticsearch }}).
 *
 * https://craftcms.com/docs/plugins/variables
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 */
class ElasticsearchVariable
{
    /**
     * Execute the given `$query` in the Elasticsearch index
     *     {{ craft.elasticsearch.results(query) }}
     * @param string $query String to search for
     * @return ElasticsearchRecord[]
     * @throws \lhs\elasticsearch\exceptions\IndexEntryException
     */
    public function results($query): array
    {
        return Elasticsearch::getInstance()->service->search($query);
    }
}
