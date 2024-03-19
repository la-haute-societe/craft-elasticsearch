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
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\jobs\IndexElementJob;
use lhs\elasticsearch\records\ElasticsearchRecord;
use yii\db\Query;

/**
 */
class ElementIndexerService extends Component
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }


    /**
     * Index the given `$element` into Elasticsearch
     * @param Element $element
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     * @throws \yii\elasticsearch\Exception If an error occurs while saving the record to the Elasticsearch server
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws IndexElementException If an error occurs while getting the indexable content of the entry. Check the previous property of the exception for more details
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     */
    public function indexElement(Element $element): ?string
    {
        $reason = $this->getReasonForNotReindexing($element);
        if ($reason !== null) {
            return $reason;
        }

        Craft::info("Indexing entry {$element->url}", __METHOD__);

        $postDate = $element instanceof Asset ? $element->dateCreated : $element->postDate;
        $expiryDate = $element instanceof Asset ? null : $element->expiryDate;

        $esRecord = $this->getElasticRecordForElement($element);
        //@formatter:off
        $esRecord->title         = $element->title;
        $esRecord->url           = $element->url;
        $esRecord->postDate      = $postDate ? Db::prepareDateForDb($postDate) : null;
        $esRecord->noPostDate    = $postDate ? false : true;
        $esRecord->expiryDate    = $expiryDate ? Db::prepareDateForDb($expiryDate) : null;
        $esRecord->noExpiryDate  = $expiryDate ? false : true;
        $esRecord->elementHandle = $element->refHandle();
        //@formatter:on

        $content = $this->getElementContent($element);
        if ($content === false) {
            $message = "Not indexing element #{$element->id} since it doesn't have a template.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        $esRecord->content = base64_encode(trim($content));

        $isSuccessfullySaved = $esRecord->save();

        if (!$isSuccessfullySaved) {
            throw new \yii\elasticsearch\Exception('Could not save elasticsearch record');
        }

        return null;
    }


    /**
     * Removes an entry from  the Elasticsearch index
     *
     * @param Element $element The entry to delete
     * @return int The number of rows deleted
     * @throws \yii\elasticsearch\Exception If the entry to be deleted cannot be found
     */
    public function deleteElement(Element $element): int
    {
        Craft::info("Deleting entry #{$element->id}: {$element->url}", __METHOD__);

        $this->deleteElementFromQueue($element);

        ElasticsearchRecord::$siteId = $element->siteId;

        return ElasticsearchRecord::deleteAll(['_id' => $element->id]);
    }

    /**
     * Removes all entries for an element from queue
     * @param Element $element
     * @return void
     * @throws \yii\base\InvalidConfigException
     */
    protected function deleteElementFromQueue(Element $element): void
    {
        $job = new IndexElementJob(
            [
                'siteId' => $element->getSite()->id,
                'elementId' => $element->id,
                'type' => get_class($element),
            ]
        );

        $queueService = Craft::$app->getQueue();
        $entries = (new Query())
            ->from($queueService->tableName)
            ->where([
                'job' => Craft::$app->getQueue()->serializer->serialize($job)
            ])->all();

        foreach ($entries as $entry) {
            if (isset($entry['id'])) {
                $methodName = $queueService instanceof \yii\queue\db\Queue ? 'remove' : 'release';
                $queueService->$methodName($entry['id']);
            }
        }
    }

    /**
     * Get the reason why an entry should NOT be reindex.
     * @param Element $element The element to consider for reindexing
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     * @throws \yii\base\InvalidConfigException
     */
    protected function getReasonForNotReindexing(Element $element): ?string
    {
        if (!(
            $element instanceof Entry
            || $element instanceof Product
            || $element instanceof DigitalProduct
            || $element instanceof Asset
        )) {
            $message = "Not indexing entry #{$element->id} since it is not an entry, an asset, a product or a digital product.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        if (!$element->enabledForSite) {
            $sitesService = Craft::$app->getSites();
            try {
                $currentSiteId = $sitesService->getCurrentSite()->id;
                $message = "Not indexing entry #{$element->id} since it is not enabled for the current site (#{$currentSiteId}).";
                Craft::debug($message, __METHOD__);
                return $message;
            } catch (SiteNotFoundException $e) {
                $message = "Not indexing entry #{$element->id} since there are no sites (therefore it can't be enabled for any site).";
                Craft::debug($message, __METHOD__);
                return $message;
            }
        }

        if (!$element->hasContent()) {
            $message = "Not indexing entry #{$element->id} since it has no content.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        if (!$element->getUrl()) {
            $message = "Not indexing entry #{$element->id} since it has no URL.";
            Craft::debug($message, __METHOD__);
            return $message;
        }

        if ($element instanceof Entry) {
            $blacklist = $this->plugin->getSettings()->blacklistedEntryTypes;
            if (in_array($element->type->handle, $blacklist, true)) {
                $message = "Not indexing entry #{$element->id} since it's in a blacklisted entry types.";
                Craft::debug($message, __METHOD__);
                return $message;
            }
        }

        if ($element instanceof Asset) {
            $blacklist = $this->plugin->getSettings()->blacklistedAssetVolumes;
            if (in_array($element->getVolume()->handle, $blacklist, true)) {
                $message = "Not indexing asset #{$element->id} since it's in a blacklisted asset volume.";
                Craft::debug($message, __METHOD__);
                return $message;
            }
        }

        return null;
    }

    /**
     * @param Element $element
     * @return bool|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws IndexElementException
     */
    protected function getElementContent(Element $element)
    {
        if ($callback = $this->plugin->getSettings()->elementContentCallback) {
            return $callback($element);
        }

        return $this->getElementIndexableContent($element);
    }

    /**
     * Get an element page content using Guzzle
     * @param Element $element
     * @return bool|string The indexable content of the entry or `false` if the entry doesn't have a template (ie. is not indexable)
     * @throws IndexElementException If anything goes wrong. Check the previous property of the exception to get more details
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getElementIndexableContent(Element $element)
    {
        Craft::debug('Getting element page content : ' . $element->url, __METHOD__);

        // Special treatment for assets
        if ($element instanceof Asset) {
            try {
                return $element->getContents();
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Request a sharable url for element in order to get content for pending ones

        // First generate a token for shared view
        /** @noinspection PhpUndefinedClassInspection */
        if ($element instanceof Product) {
            $token = Craft::$app->getTokens()->createToken(
                [
                    'commerce/products-preview/view-shared-product',
                    ['productId' => $element->id, 'siteId' => $element->siteId],
                ]
            );
        } else {
            $schemaVersion = Craft::$app->getInstalledSchemaVersion();
            if (version_compare($schemaVersion, '3.2.0', '>=')) {
                $token = Craft::$app->getTokens()->createToken(
                    [
                        'preview/preview',
                        [
                            'elementType' => get_class($element),
                            'canonicalId' => $element->canonicalId,
                            'sourceId'    => $element->id,
                            'siteId'      => $element->siteId,
                            'draftId'     => null,
                            'revisionId'  => null,
                        ],
                    ]
                );
            } else {
                $token = Craft::$app->getTokens()->createToken(
                    [
                        'entries/view-shared-entry',
                        ['entryId' => $element->id, 'siteId' => $element->siteId],
                    ]
                );
            }
        }

        // Generate the sharable url based on the previously generated token
        $url = UrlHelper::urlWithToken($element->getUrl(), $token);

        // Request the page content with GuzzleHttp\Client
        $client = new \GuzzleHttp\Client(['connect_timeout' => 10]);
        try {
            $res = $client->request('GET', $url);
            if ($res->getStatusCode() === 200) {
                return $this->extractIndexablePart($res->getBody());
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Craft::error('Could not get element content: ' . $e->getMessage(), __METHOD__);
            throw new IndexElementException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new IndexElementException(
                Craft::t(
                    ElasticsearchPlugin::PLUGIN_HANDLE,
                    'An error occurred while parsing the element page content: {previousExceptionMessage}',
                    ['previousExceptionMessage' => $e->getMessage()]
                ), 0, $e
            );
        }

        return false;
    }

    /**
     * @param $html
     * @return string
     */
    protected function extractIndexablePart(string $html): string
    {
        /** @noinspection NullPointerExceptionInspection NPE cannot happen here. */
        if ($callback = ElasticsearchPlugin::getInstance()->getSettings()->contentExtractorCallback) {
            $html = $callback($html);
        }

        if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $html, $body)) {
            $html = '<!DOCTYPE html>' . trim($body[1]);
        }

        return trim($html);
    }

    /**
     * @param Element $element
     * @return ElasticsearchRecord
     */
    protected function getElasticRecordForElement(Element $element): ElasticsearchRecord
    {
        ElasticsearchRecord::$siteId = $element->siteId;

        /** @var ElasticsearchRecord|null $esRecord */
        $esRecord = ElasticsearchRecord::findOne($element->id);

        if ($esRecord === null) {
            $esRecord = new ElasticsearchRecord();
            ElasticsearchRecord::$siteId = $element->siteId;
            $esRecord->set_id($element->id);
        }
        $esRecord->setElement($element);
        return $esRecord;
    }
}
