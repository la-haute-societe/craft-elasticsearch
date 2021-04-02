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

use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\models\IndexableElementModel;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Manage Craft Elasticsearch indexes from the command line
 */
class ElasticsearchController extends Controller
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    /**
     * Reindex entries, assets, products & digital products in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexAll(): int
    {
        $indexableElementModels = $this->plugin->service->getIndexableElementModels();

        return $this->reindexElements($indexableElementModels, 'everything');
    }

    /**
     * Reindex entries in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexEntries(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableEntryModels();

        return $this->reindexElements($elementDescriptors, 'entries');
    }

    /**
     * Reindex assets in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexAssets(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableAssetModels();

        return $this->reindexElements($elementDescriptors, 'assets');
    }

    /**
     * Reindex products in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexProducts(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableProductModels();

        return $this->reindexElements($elementDescriptors, 'products');
    }

    /**
     * Reindex digital products in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexDigitalProducts(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableDigitalProductModels();

        return $this->reindexElements($elementDescriptors, 'digitalProducts');
    }


    /**
     * Remove index & create an empty one for all sites
     *
     * @throws IndexElementException If an error occurs while recreating the indices on the Elasticsearch instance
     */
    public function actionRecreateEmptyIndexes(): void
    {
        ElasticsearchPlugin::getInstance()->indexManagementService->recreateIndexesForAllSites();
    }

    /**
     * @param IndexableElementModel[] $indexableElementModels
     * @param string                  $type
     * @return int A shell exit code
     * @throws IndexElementException If an error occurs while reindexing the entries
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    protected function reindexElements(array $indexableElementModels, string $type): int
    {
        $this->stdout(PHP_EOL);
        $this->stdout("Craft Elasticsearch plugin | Reindex $type", Console::FG_GREEN);
        $this->stdout(PHP_EOL);

        // Reindex elements
        $elementCount = count($indexableElementModels);
        $processedElementCount = 0;
        $errorCount = 0;
        Console::startProgress(0, $elementCount);

        foreach ($indexableElementModels as $indexableElementModel) {
            $errorMessage = $this->reindexElement($indexableElementModel);

            if ($errorMessage === null) {
                Console::updateProgress(++$processedElementCount, $elementCount);
            } else {
                $errorCount++;
                Console::updateProgress(++$processedElementCount, $elementCount);
                $this->stderr($errorMessage);
            }
        }

        Console::endProgress();
        $exitCode = $errorCount === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;

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
     * @param IndexableElementModel $indexableElementModel
     * @return string|null `null` if the element was successfully reindexed, an error message explaining why it wasn't otherwise
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    protected function reindexElement(IndexableElementModel $indexableElementModel): ?string
    {
        try {
            $element = $indexableElementModel->getElement();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return ElasticsearchPlugin::getInstance()->elementIndexerService->indexElement($element);
    }
}
