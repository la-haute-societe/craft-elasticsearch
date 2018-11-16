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
use craft\elements\Entry;
use craft\queue\BaseJob;
use lhs\elasticsearch\Elasticsearch;
use yii\helpers\VarDumper;

/**
 * Reindex a single entry
 */
class IndexElement extends BaseJob
{
    /** @var int Id of the site */
    public $siteId;

    /*** @var int Id of the element to index */
    public $elementId;

    /**
     * @inheritdoc
     */
    public function execute($queue)
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($this->siteId);

        $sites->setCurrentSite($site);
        $entry = Entry::findOne($this->elementId);
        Craft::debug(VarDumper::dumpAsString($entry), __METHOD__);

        if ($entry) {
            Elasticsearch::getInstance()->service->indexEntry($entry);
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
