<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\jobs;

use Craft;
use craft\commerce\elements\Product;
use craft\elements\Entry;
use craft\queue\BaseJob;
use lhs\elasticsearch\Elasticsearch;

/**
 * Reindex a single entry
 */
class IndexElement extends BaseJob
{
    /** @var int Id of the site */
    public $siteId;

    /*** @var int Id of the element to index */
    public $elementId;

    /*** @var string Type of Element to index */
    public $type;

    /**
     * @inheritdoc
     */
    public function execute($queue)
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($this->siteId);
        $sites->setCurrentSite($site);

        $element = $this->type === Product::class ? Product::findOne($this->elementId) : Entry::findOne($this->elementId);

        if ($element) {
            Elasticsearch::getInstance()->service->indexElement($element);
        }
    }

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        return Craft::t(
            Elasticsearch::TRANSLATION_CATEGORY,
            'Index a page in Elasticsearch'
        );
    }
}
