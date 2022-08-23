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
use craft\queue\BaseJob;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\models\IndexableElementModel;

/**
 * Reindex a single entry
 */
class IndexElementJob extends BaseJob
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
    public function execute($queue): void
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($this->siteId);
        $sites->setCurrentSite($site);

        $model = new IndexableElementModel();
        $model->elementId = $this->elementId;
        $model->siteId = $this->siteId;
        $model->type = $this->type;
        Elasticsearch::getInstance()->elementIndexerService->indexElement($model->getElement());
    }

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        $type = ($pos = strrpos($this->type, '\\')) ? substr($this->type, $pos + 1) : $this->type;

        return Craft::t(
            Elasticsearch::PLUGIN_HANDLE,
            sprintf(
                'Index %s #%d (site #%d) in Elasticsearch',
                $type,
                $this->elementId,
                $this->siteId
            )
        );
    }
}
