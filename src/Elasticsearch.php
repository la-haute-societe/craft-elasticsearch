<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch;

use Craft;
use craft\base\Plugin;
use craft\commerce\elements\Product;
use craft\console\Application as ConsoleApplication;
use craft\elements\Entry;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\ArrayHelper;
use craft\queue\Queue;
use craft\services\Plugins;
use craft\services\Utilities;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\models\Settings;
use lhs\elasticsearch\services\Elasticsearch as ElasticsearchService;
use lhs\elasticsearch\services\ReindexQueueManagement;
use lhs\elasticsearch\utilities\RefreshElasticsearchIndexUtility;
use lhs\elasticsearch\variables\ElasticsearchVariable;
use yii\base\Event;
use yii\elasticsearch\Connection;
use yii\elasticsearch\DebugPanel;
use yii\elasticsearch\Exception;
use yii\queue\ExecEvent;

/**
 * @property  services\Elasticsearch service
 * @property  services\ReindexQueueManagement reindexQueueManagementService
 * @property  Settings settings
 * @property  Connection elasticsearch
 */
class Elasticsearch extends Plugin
{
    const EVENT_ERROR_NO_ATTACHMENT_PROCESSOR = 'errorNoAttachmentProcessor';
    const PLUGIN_HANDLE = 'elasticsearch';
    const APP_COMPONENT_NAME = self::PLUGIN_HANDLE;
    const TRANSLATION_CATEGORY = self::PLUGIN_HANDLE;

    public $hasCpSettings = true;

    public function init()
    {
        parent::init();

        $isCommerceEnabled = $this->isCommerceEnabled();

        $this->setComponents([
            'service'                       => ElasticsearchService::class,
            'reindexQueueManagementService' => ReindexQueueManagement::class,
        ]);

        $this->initializeElasticConnector();

        // Add console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lhs\elasticsearch\console\controllers';
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            // Remove entry from the index upon deletion
            Event::on(
                Entry::class,
                Entry::EVENT_AFTER_DELETE,
                function (Event $event) {
                    /** @var entry $entry */
                    $entry = $event->sender;
                    try {
                        $this->service->deleteEntry($entry);
                    } catch (Exception $e) {
                        // Noop, the element must have already been deleted
                    }
                }
            );

            // Index entry upon save (creation or update)
            Event::on(
                Entry::class,
                Entry::EVENT_AFTER_SAVE,
                function (Event $event) {
                    /** @var Entry $entry */
                    $entry = $event->sender;
                    if ($entry->enabled) {
                        $this->reindexQueueManagementService->enqueueJob($entry->id, $entry->siteId);
                    } else {
                        try {
                            $this->service->deleteEntry($entry);
                        } catch (Exception $e) {
                            // Noop, the element must have already been deleted
                        }
                    }
                }
            );

            if ($isCommerceEnabled) {
                // Index product upon save (creation or update)
                Event::on(
                    Product::class,
                    Product::EVENT_AFTER_SAVE,
                    function (Event $event) {
                        /** @var Product $product */
                        $product = $event->sender;
                        if ($product->enabled) {
                            //$this->reindexQueueManagementService->enqueueJob($product->id, $product->siteId);
                            $this->service->indexElement($product);
                        } else {
                            try {
                                $this->service->deleteEntry($product);
                            } catch (Exception $e) {
                                // Noop, the element must have already been deleted
                            }
                        }
                    }
                );
            }

            // Re-index all entries when plugin settings are saved
            Event::on(
                Plugins::class,
                Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
                function (PluginEvent $event) {
                    if ($event->plugin === $this) {
                        $this->onPluginSettingsSaved();
                    }
                }
            );

            // On reindex job success, remove its id from the cache (cache is used to keep track of reindex jobs and clear those having failed before reindexing all entries)
            Event::on(
                Queue::class,
                Queue::EVENT_AFTER_EXEC,
                function (ExecEvent $event) {
                    $this->reindexQueueManagementService->removeJob($event->id);
                }
            );

            // Register the plugin's CP utility
            Event::on(
                Utilities::class,
                Utilities::EVENT_REGISTER_UTILITY_TYPES,
                function (RegisterComponentTypesEvent $event) {
                    $event->types[] = RefreshElasticsearchIndexUtility::class;
                }
            );

            // Register our CP routes
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                function (RegisterUrlRulesEvent $event) {
                    $event->rules['elasticsearch/cp/test-connection'] = 'elasticsearch/cp/test-connection';
                    $event->rules['elasticsearch/cp/reindex-perform-action'] = 'elasticsearch/cp/reindex-perform-action';
                }
            );

            // Display a flash message if the ingest attachment plugin isn't activated on the Elasticsearch instance
            Event::on(
                self::class,
                self::EVENT_ERROR_NO_ATTACHMENT_PROCESSOR,
                function () {
                    $application = Craft::$app;

                    if ($application instanceof \yii\web\Application) {
                        $application->getSession()->setError(Craft::t(
                            self::TRANSLATION_CATEGORY,
                            'The ingest-attachment plugin seems to be missing on your Elasticsearch instance.'
                        ));
                    }
                }
            );

            if (YII_DEBUG) {
                // Add the Elasticsearch panel to the Yii debug bar
                Event::on(
                    Application::class,
                    Application::EVENT_BEFORE_REQUEST,
                    function () {
                        /** @var \yii\debug\Module $debugModule */
                        $debugModule = Craft::$app->getModule('debug');
                        $debugModule->panels['elasticsearch'] = new DebugPanel(['module' => $debugModule]);
                    }
                );
            }
        }

        // Register variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('elasticsearch', ElasticsearchVariable::class);
            }
        );

        // Register our site routes (used by the console commands to reindex entries)
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['elasticsearch/get-all-entries'] = 'elasticsearch/site/get-all-entries';
                $event->rules['elasticsearch/reindex-all'] = 'elasticsearch/site/reindex-all';
                $event->rules['elasticsearch/reindex-entry'] = 'elasticsearch/site/reindex-entry';
            }
        );

        Craft::info("{$this->name} plugin loaded", __METHOD__);
    }

    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * @return Connection
     */
    public static function getConnection(): Connection
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        /** @noinspection OneTimeUseVariablesInspection */
        /** @var Connection $connection */
        $connection = Craft::$app->get(self::APP_COMPONENT_NAME);

        return $connection;
    }


    /**
     * Initialize the Elasticsearch connector
     * @noinspection PhpDocMissingThrowsInspection Can't happen since a valid config array is passed
     * @param Settings $settings
     * @throws \yii\base\InvalidConfigException If the configuration passed to the yii2-elasticsearch module is invalid
     */
    public function initializeElasticConnector($settings = null)
    {
        if ($settings === null) {
            $settings = $this->getSettings();
        }

        if ($settings->elasticsearchComponentConfig !== null) {
            $definition = $settings->elasticsearchComponentConfig;
        } else {
            $protocol = parse_url($settings->elasticsearchEndpoint, PHP_URL_SCHEME);
            $endpointUrlWithoutProtocol = preg_replace("#^$protocol(?:://)?#", '', $settings->elasticsearchEndpoint);

            $definition = [
                'connectionTimeout' => 10,
                'autodetectCluster' => false,
                'nodes'             => [
                    [
                        'protocol'     => $protocol ?? 'http',
                        'http_address' => $endpointUrlWithoutProtocol,
                        'http'         => ['publish_address' => $settings->elasticsearchEndpoint],
                    ],
                ],
            ];

            if ($settings->isAuthEnabled) {
                $definition['auth'] = [
                    'username' => $settings->username,
                    'password' => $settings->password,
                ];
            }
        }

        $definition['class'] = Connection::class;

        // Fix nodes. When cluster auto detection is disabled, the Elasticsearch component crashes when closing connections…
        array_walk($definition['nodes'], function (&$node) {
            if (!isset($node['http'])) {
                $node['http'] = [];
            }

            if (!isset($node['http']['publish_address'])) {
                $node['http']['publish_address'] = sprintf(
                    '%s://%s',
                    $node['protocol'] ?? 'http',
                    $node['http_address']
                );
            }
        });

        /** @noinspection PhpUnhandledExceptionInspection Can't happen since a valid config array is passed */
        Craft::$app->set(self::APP_COMPONENT_NAME, $definition);
    }


    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        $settings = new Settings();
        return $settings;
    }

    //    public function setSettings(array $settings)
    //    {
    //        // Ensure all sites have a blacklist (at least an empty one)
    //        $siteIds = Craft::$app->sites->getAllSiteIds();
    //        if (!isset($settings['blacklistedSections'])) {
    //            $settings['blacklistedSections'] = [];
    //        }
    //        $settings['blacklistedSections'] = array_replace(array_fill_keys($siteIds, []), $settings['blacklistedEntryTypes']);
    //
    //        parent::setSettings($settings);
    //    }


    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     */
    protected function settingsHtml(): string
    {
        $sections = ArrayHelper::map(Craft::$app->sections->getAllSections(), 'id', function ($data) {
            $output = ['label' => Craft::t('site', $data->name)];
            $output['types'] = ArrayHelper::map($data->getEntryTypes(), 'id', function ($data) {
                return ['label' => Craft::t('site', $data->name)];
            });
            return $output;
        });

        return Craft::$app->view->renderTemplate(
            'elasticsearch/cp/settings',
            [
                'settings' => $this->getSettings(),
                'sections' => $sections,
            ]
        );
    }

    protected function onPluginSettingsSaved()
    {
        /** @noinspection PhpUnhandledExceptionInspection If there was an error in the configuration, it would have prevented validation */
        $this->initializeElasticConnector(); //FIXME: Check if this is needed

        Craft::debug('Elasticsearch plugin settings saved => re-index all entries', __METHOD__);
        try {
            $this->service->recreateIndexesForAllSites();

            // Remove previous reindexing jobs as all entries will be reindexed anyway
            $this->reindexQueueManagementService->clearJobs();
            $this->reindexQueueManagementService->enqueueReindexJobs($this->service->getEnabledEntries());
            if ($this->isCommerceEnabled()) {
                $this->reindexQueueManagementService->enqueueReindexJobs($this->service->getEnabledProducts());
            }
        } catch (IndexElementException $e) {
            /** @noinspection PhpUnhandledExceptionInspection This method should only be called in a web context so Craft::$app->getSession() will never throw */
            Craft::$app->getSession()->setError($e->getMessage());
        }
    }

    public function beforeSaveSettings(): bool
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        $settings->elasticsearchComponentConfig = null;
        return parent::beforeSaveSettings();
    }

    /**
     * Check for presence of Craft Commerce Plugin
     * @return bool
     */
    public function isCommerceEnabled(): bool
    {
        return class_exists(\craft\commerce\Plugin::class);
    }
}
