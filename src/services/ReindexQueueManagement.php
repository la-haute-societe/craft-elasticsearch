<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\services;

use Craft;
use craft\base\Component;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\jobs\IndexElement as IndexElementJob;

/**
 * Service used to manage the reindex job queue.
 * It allows clearing failed reindexing jobs before reindexing all entries.
 * @property array $cache
 */
class ReindexQueueManagement extends Component
{
    const CACHE_KEY = Elasticsearch::PLUGIN_HANDLE . '_reindex_jobs';

    /**
     * Add reindex job for the given entries
     * @param array $elements An array of elements. Each entry is an associative array having, at least, the `siteId`
     *                       `elementId` and `elementType` keys
     */
    public function enqueueReindexJobs(array $elements)
    {
        $jobIds = [];
        foreach ($elements as $element) {
            $jobIds[] = Craft::$app->getQueue()->push(new IndexElementJob([
                'siteId'    => $element['siteId'],
                'elementId' => $element['elementId'],
                'type'      => $element['type']
            ]));
        }

        $jobIds = array_unique(array_merge($jobIds, $this->getCache()));

        /** @noinspection SummerTimeUnsafeTimeManipulationInspection */
        Craft::$app->getCache()->set(self::CACHE_KEY, $jobIds, 24 * 60 * 60);
    }

    /**
     * Remove all jobs from the queue
     */
    public function clearJobs()
    {
        $jobIds = $this->getCache();
        foreach ($jobIds as $jobId) {
            $this->removeJobFromQueue($jobId);
        }

        $cacheService = Craft::$app->getCache();
        $cacheService->delete(self::CACHE_KEY);
    }

    /**
     * Remove a job from the queue
     * @param int $id The id of the job to remove
     */
    public function removeJob(int $id)
    {
        $this->removeJobFromQueue($id);
        $this->removeJobIdFromCache($id);
    }

    public function enqueueJob(int $entryId, int $siteId, string $type)
    {
        $jobId = Craft::$app->queue->push(new IndexElementJob([
            'siteId'    => $siteId,
            'elementId' => $entryId,
            'type'      => $type
        ]));

        $this->addJobIdToCache($jobId);
    }

    /**
     * Remove a job from the queue. This should work with any cache backend.
     * This does NOT remove the job id from the cache
     * @param int $id The id of the job to remove
     */
    protected function removeJobFromQueue(int $id)
    {
        $queueService = Craft::$app->getQueue();
        $methodName = $queueService instanceof \yii\queue\db\Queue ? 'remove' : 'release';
        $queueService->$methodName($id);
    }

    /**
     * Add a job id to the cache
     * @param int $id The job id to add to the cache
     */
    protected function addJobIdToCache(int $id)
    {
        $jobIds = $this->getCache();
        $jobIds[] = $id;

        Craft::$app->getCache()->set(self::CACHE_KEY, $jobIds);
    }

    /**
     * Remove a job id from the cache
     * @param int $id The job id to remove from the cache
     */
    protected function removeJobIdFromCache(int $id)
    {
        $jobIds = array_diff($this->getCache(), [$id]);

        Craft::$app->getCache()->set(self::CACHE_KEY, $jobIds);
    }

    /**
     * Get the job ids from the cache
     * @return array An array of job ids
     */
    protected function getCache(): array
    {
        $cache = Craft::$app->getCache();
        $jobIds = $cache->get(self::CACHE_KEY);

        if ($jobIds === false || !is_array($jobIds)) {
            $jobIds = [];
        }

        return $jobIds;
    }
}
