<?php
/**
 * Generic plugin for Craft CMS 3.x
 *
 * This is a generic Craft CMS plugin
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use lhs\elasticsearch\Elasticsearch;
use yii\helpers\VarDumper;

/**
 * IndexEntry job
 *
 * Jobs are run in separate process via a Queue of pending jobs. This allows
 * you to spin lengthy processing off into a separate PHP process that does not
 * block the main process.
 *
 * You can use it like this:
 *
 * use lhs\elasticsearch\jobs\IndexElement as IndexElementJob;
 *
 * $queue = Craft::$app->getQueue();
 * $jobId = $queue->push(new IndexEntryJob([
 *     'description' => Craft::t('generic', 'This overrides the default description'),
 *     'someAttribute' => 'someValue',
 * ]));
 *
 * The key/value pairs that you pass in to the job will set the public properties
 * for that object. Thus whatever you set 'someAttribute' to will cause the
 * public property $someAttribute to be set in the job.
 *
 * Passing in 'description' is optional, and only if you want to override the default
 * description.
 *
 * More info: https://github.com/yiisoft/yii2-queue
 *
 * @author    Alban Jubert
 * @package   Generic
 * @since     1.0.0
 */
class IndexElement extends BaseJob
{
    // Public Properties
    // =========================================================================

    public $siteId;
    /**
     * Element ID to be indexed
     *
     * @var int
     */
    public $elementId;

    // Public Methods
    // =========================================================================

    /**
     * When the Queue is ready to run your job, it will call this method.
     * You don't need any steps or any other special logic handling, just do the
     * jobs that needs to be done here.
     *
     * More info: https://github.com/yiisoft/yii2-queue
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

    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
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
