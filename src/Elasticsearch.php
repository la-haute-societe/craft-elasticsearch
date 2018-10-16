<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Entry;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\queue\Queue;
use craft\services\Plugins;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use lhs\elasticsearch\exceptions\IndexEntryException;
use lhs\elasticsearch\models\Settings;
use lhs\elasticsearch\services\Elasticsearch as ElasticsearchService;
use lhs\elasticsearch\services\ReindexQueueManagement;
use lhs\elasticsearch\utilities\ElasticsearchUtilities;
use lhs\elasticsearch\variables\ElasticsearchVariable;
use yii\base\Event;
use yii\elasticsearch\Connection;
use yii\elasticsearch\DebugPanel;
use yii\queue\ExecEvent;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 *
 * @property  services\Elasticsearch service
 * @property  services\ReindexQueueManagement reindexQueueManagementService
 * @property  Settings               settings
 * @property  Connection             elasticsearch
 */
class Elasticsearch extends Plugin
{
    public $name = 'Elasticsearch';

    const PLUGIN_HANDLE = 'elasticsearch';
    const APP_COMPONENT_NAME = self::PLUGIN_HANDLE;
    const TRANSLATION_CATEGORY = self::PLUGIN_HANDLE;

    // Public Methods
    // =========================================================================


    public function init()
    {
        parent::init();

        $this->setComponents([
            'service' => ElasticsearchService::class,
            'reindexQueueManagementService' => ReindexQueueManagement::class,
        ]);

        $this->initializeElasticConnector();

        // Add console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lhs\elasticsearch\console\controllers';
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

        // Remove entry from the index upon deletion
        Event::on(
            Element::class,
            Element::EVENT_AFTER_DELETE,
            function (Event $event) {
                /** @var entry $entry */
                $entry = $event->sender;
                $this->service->deleteEntry($entry);
            }
        );

        // Index entry upon save (creation or update)
        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function (Event $event) {
                $element = $event->sender;
                if ($element instanceof Entry) {
                    if ($element->enabled) {
                        $this->reindexQueueManagementService->enqueueJob($element->id, $element->siteId);
                    } else {
                        $this->service->deleteEntry($element);
                    }
                }
            }
        );

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
                $event->types[] = ElasticsearchUtilities::class;
            }
        );

        // Register CP permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions['elasticsearch'] = [
                    'reindex' => [
                        'label' => Craft::t(
                            self::TRANSLATION_CATEGORY,
                            'Refresh Elasticsearch index'
                        ),
                    ],
                ];
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

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        $siteIds = Craft::$app->sites->getAllSiteIds();
        $settings = new Settings();

        // Ensure all sites have a blacklist (at least an empty one)
        $settings->blacklistedSections = array_replace(array_fill_keys($siteIds, []), $settings['blacklistedSections']);

        return $settings;
    }

    public function setSettings(array $settings)
    {
        // Ensure all sites have a blacklist (at least an empty one)
        $siteIds = Craft::$app->sites->getAllSiteIds();
        if (!isset($settings['blacklistedSections'])) {
            $settings['blacklistedSections'] = [];
        }
        $settings['blacklistedSections'] = array_replace(array_fill_keys($siteIds, []), $settings['blacklistedSections']);

        parent::setSettings($settings);
    }


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
        $sections = [];
        array_map(function ($section) use (&$sections) {
            $sections[$section->id] = Craft::t('site', $section->name);
        }, Craft::$app->sections->getAllSections());

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
        $this->initializeElasticConnector();

        Craft::debug('Elasticsearch plugin settings saved => re-index all entries', __METHOD__);
        try {
            $this->service->recreateIndexesForAllSites();

            // Remove previous reindexing jobs as all entries will be reindexed anyway
            $this->reindexQueueManagementService->clearJobs();
            $this->reindexQueueManagementService->enqueueReindexJobs($this->service->getEnabledEntries());
        } catch (IndexEntryException $e) {
            /** @noinspection PhpUnhandledExceptionInspection If this happens, then something is clearly very wrong */
            Craft::$app->getSession()->setError($e->getMessage());
        }
    }

    /**
     * Initialize the Elasticsearch connector
     * @param Settings $settings
     * @throws \yii\base\InvalidConfigException If the configuration passed to the yii2-elasticsearch module is invalid
     */
    public function initializeElasticConnector($settings = null)
    {
        if ($settings === null) {
            $settings = $this->getSettings();
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        $definition = [
            'class' => Connection::class,
            'nodes' => [['http_address' => $settings->http_address]],
        ];

        if ($settings->auth_enabled) {
            $definition['auth'] = [
                'username' => $settings->auth_username,
                'password' => $settings->auth_password,
            ];
        }

        Craft::$app->set(self::APP_COMPONENT_NAME, $definition);
    }
}
