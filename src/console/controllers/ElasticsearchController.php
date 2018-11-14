<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\console\controllers;

use Craft;
use craft\records\Site;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Pool;
use function GuzzleHttp\Psr7\build_query;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\exceptions\IndexEntryException;
use Psr\Http\Message\ResponseInterface;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Allow various operation for elasticsearch index
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 */
class ElasticsearchController extends Controller
{
    /**
     * @var string The public domain name of the site. Will be used to replace the domain name part of the `siteBaseUrl` argument.
     * This may be useful in advanced server setups (eg. when the server running this CLI script cannot resolve the public domain name).
     */
    public $publicDomainName;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['publicDomainName']);
    }

    // Public Methods
    // =========================================================================

    /**
     * Reindex Craft entries into the Elasticsearch instance
     *
     * @param string $siteBaseUrl The base URL to access the site to index.
     *                            Should include the protocol.
     *                            If not http:// will be prepended.
     *
     * @return int A shell exit code. 0 indicated success, anything else indicates an error
     * @throws IndexEntryException If an error occurs while reindexing the entries
     */
    public function actionReindexAll($siteBaseUrl)
    {
        $this->stdout(PHP_EOL);
        $this->stdout('Craft Elasticsearch plugin | Reindex all entries', Console::FG_GREEN);
        $this->stdout(PHP_EOL);

        // Ensure that `siteBaseUrl` includes the protocol
        if (!preg_match('|^https?://.+|', $siteBaseUrl)) {
            $siteBaseUrl = 'http://'.$siteBaseUrl;
        }
        $httpClient = new Client();

        // Get entries
        $entries = $this->getEntriesToReindex($httpClient, $siteBaseUrl);

        // Reindex entries
        $exitCode = $this->reindexEntries($httpClient, $siteBaseUrl, $entries);

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
     * @throws IndexEntryException If an error occurs while recreating the indices on the Elasticsearch instance
     */
    public function actionRecreateEmptyIndexes()
    {
        ElasticsearchPlugin::getInstance()->service->recreateIndexesForAllSites();
    }

    /**
     * Get an array of associative arrays representing the entries to reindex
     * (an associative array representing an entry has the following keys: id and url)
     *
     * @param Client $httpClient  The instance of Guzzle HTTP Client to use to make the HTTP request
     * @param string $siteBaseUrl The base URL to access the site to index.
     *                            Should include the protocol.
     *                            If not http:// will be prepended.
     *
     * @return array An associative arrays representing the entries to reindex
     * @throws IndexEntryException If an error occurs while fetching the list of entries to reindex
     */
    protected function getEntriesToReindex(Client $httpClient, string $siteBaseUrl): array
    {
        $response = $httpClient->post("{$siteBaseUrl}/elasticsearch/get-all-entries", [
            'headers' => ['Accept' => 'application/json'],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $reason = $response->getReasonPhrase();
            throw new IndexEntryException("Unexpected response from endpoint: {$statusCode} {$reason}");
        }

        $decodedResponse = json_decode($response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new IndexEntryException('Invalid JSON received from endpoint.');
        }

        if (!property_exists($decodedResponse, 'entries')) {
            throw new IndexEntryException('Unexpected JSON content received from endpoint.');
        }

        return $decodedResponse->entries;
    }

    /**
     * Reindex the given $entries. The reindexing process takes place in a web
     * context as running it in a console context messes up some variables and
     * URLs.
     *
     * @param Client $httpClient
     * @param string $siteBaseUrl The base URL to access the site to index.
     *                            Should include the protocol.
     *                            If not http:// will be prepended.
     * @param array  $entries
     *
     * @return int A shell exit code. 0 indicated success, anything else indicates an error
     */
    protected function reindexEntries(Client $httpClient, string $siteBaseUrl, array $entries)
    {
        $reindexEntryEndpoint = "{$siteBaseUrl}/elasticsearch/reindex-entry";

        $reindexEntryRequests = array_map(function($entry) use ($reindexEntryEndpoint) {
            return new Request(
                'GET',
                $reindexEntryEndpoint.'?'.build_query(['entryId' => $entry->entryId, 'siteId' => $entry->siteId]),
                ['Accept' => 'application/json']
            );
        }, $entries);

        $entryCount = count($entries);
        $processedEntryCount = 0;
        $errorCount = 0;
        Console::startProgress(0, $entryCount);

        $reindexEntryRequestPool = new Pool($httpClient, $reindexEntryRequests, [
            'concurrency' => 5,
            'fulfilled'   => function(Response $response, $index) use (&$processedEntryCount, $entryCount) {
                Console::updateProgress(++$processedEntryCount, $entryCount);
            },
            'rejected'    => function(ServerException $reason, $index) use (&$processedEntryCount, $entryCount, &$errorCount) {
                $errorCount++;
                Console::updateProgress(++$processedEntryCount, $entryCount);
                $this->printError($reason->getResponse(), $index);
            },
        ]);


        $promise = $reindexEntryRequestPool->promise();
        $promise->wait();
        Console::endProgress();

        return $errorCount === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    protected function printError(ResponseInterface $response, int $requestIndex)
    {
        $this->stderr(PHP_EOL.PHP_EOL);
        $this->stderr(sprintf(
            '%s%1$s  Request #%d failed: %d %s%1$s',
            PHP_EOL,
            $requestIndex,
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ), Console::FG_GREY, Console::BG_RED, Console::BOLD);

        // Try to display details if the response is JSON-encoded
        $decodedResponse = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Can't do much more!
            return;
        }

        if (isset($decodedResponse['error'])) {
            $this->stderr('  '.$decodedResponse['error'].PHP_EOL, Console::BG_RED, Console::FG_GREY);
        }
        if (isset($decodedResponse['previousMessage'])) {
            $this->stderr('  ↳ Caused by: '.$decodedResponse['previousMessage'].PHP_EOL, Console::BG_RED, Console::FG_GREY);
        }
        $this->stderr(PHP_EOL);

        if (isset($decodedResponse['trace'])) {
            $this->stderr(PHP_EOL.'Stack trace:'.PHP_EOL);
            $this->stderr($decodedResponse['trace'].PHP_EOL);
        }
        if (isset($decodedResponse['previousTrace'])) {
            $this->stderr(PHP_EOL.'Previous exception stack trace:'.PHP_EOL);
            $this->stderr($decodedResponse['previousTrace'].PHP_EOL);
        }

        $this->stderr(PHP_EOL);
    }
}
