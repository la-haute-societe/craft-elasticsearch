<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\variables;

use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\records\ElasticsearchRecord;

/**
 * This Twig variable allows running searches from the frontend templates
 */
class ElasticsearchVariable
{
    /**
     * Execute the given `$query` in the Elasticsearch index
     *     {{ craft.elasticsearch.results(query) }}
     * @param string $query String to search for
     * @return ElasticsearchRecord[]
     * @throws \lhs\elasticsearch\exceptions\IndexElementException
     */
    public function search($query): array
    {
        return Elasticsearch::getInstance()->service->search($query ?? '');
    }
}
