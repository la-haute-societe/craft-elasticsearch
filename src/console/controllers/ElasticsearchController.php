<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\console\controllers;

use Craft;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\exceptions\IndexElementException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;

/**
 * Manage Craft Elasticsearch indexes from the command line
 */
class ElasticsearchController extends Controller
{
    /**
     * Reindex Craft entries into the Elasticsearch instance
     *
     * @return int A shell exit code. 0 indicated success, anything else indicates an error
     * @throws IndexElementException If an error occurs while reindexing the entries
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidRouteException
     * @throws \yii\console\Exception
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexAll(): int
    {
        $this->stdout(PHP_EOL);
        $this->stdout('Craft Elasticsearch plugin | Reindex all elements', Console::FG_GREEN);
        $this->stdout(PHP_EOL);

        // Get elements
        $elements = $this->getElementsToReindex();

        // Reindex elements
        $exitCode = $this->reindexElements($elements);

        // Print summary message
        $this->stdout(PHP_EOL);
        $message = $this->ansiFormat('Done', Console::FG_GREEN);
        if ($exitCode > 0) {
            $message = $this->ansiFormat('Done with errors', Console::FG_RED);
        }
        $this->stdout($message);
        $this->stdout(PHP_EOL);

        return $exitCode;
    }

    /**
     * Remove index & create an empty one for all sites
     *
     * @throws IndexElementException If an error occurs while recreating the indices on the Elasticsearch instance
     */
    public function actionRecreateEmptyIndexes()
    {
        ElasticsearchPlugin::getInstance()->service->recreateIndexesForAllSites();
    }

    /**
     * Get an array of associative arrays representing the elements to reindex
     *
     * @return array An associative arrays representing the entries to reindex
     */
    protected function getElementsToReindex(): array
    {
        $elasticsearch = ElasticsearchPlugin::getInstance();
        $elements = $elasticsearch->service->getEnabledEntries();
        if ($elasticsearch->isCommerceEnabled()) {
            $elements = ArrayHelper::merge($elements, $elasticsearch->service->getEnabledProducts());
        }
        return $elements;
    }

    /**
     * Reindex the given $entries and show a progress bar.
     *
     * @param array $elements
     *
     * @return int A shell exit code. 0 indicated success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidRouteException
     * @throws \yii\console\Exception
     * @throws \yii\elasticsearch\Exception
     */
    protected function reindexElements(array $elements): int
    {
        $elementCount = count($elements);
        $processedElementCount = 0;
        $errorCount = 0;
        Console::startProgress(0, $elementCount);

        foreach ($elements as $index => $elementParams) {
            $element = $elementParams['type'] === 'craft\\commerce\\elements\\Product' ? craft\commerce\Plugin::getInstance()->getProducts()->getProductById($elementParams['elementId'], $elementParams['siteId']) : Craft::$app->getEntries()->getEntryById($elementParams['elementId'], $elementParams['siteId']);

            if ($element === null) {
                throw new IndexElementException(Craft::t(
                    ElasticsearchPlugin::TRANSLATION_CATEGORY,
                    'No such element (element #{elementId} / site #{siteId}',
                    ['elementId' => $elementParams->elementId, 'siteId' => $elementParams->siteId]
                ));
            }

            $result = ElasticsearchPlugin::getInstance()->service->indexElement($element);

            if ($result === null) {
                Console::updateProgress(++$processedElementCount, $elementCount);
            } else {
                $errorCount++;
                Console::updateProgress(++$processedElementCount, $elementCount);
                $this->stderr($result);
            }
        }

        Console::endProgress();

        return $errorCount === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
