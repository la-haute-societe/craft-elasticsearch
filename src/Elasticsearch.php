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
use craft\services\Plugins;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use lhs\elasticsearch\jobs\IndexElement;
use lhs\elasticsearch\models\Settings;
use lhs\elasticsearch\services\Elasticsearch as ElasticsearchService;
use lhs\elasticsearch\utilities\ElasticsearchUtilities;
use lhs\elasticsearch\variables\ElasticsearchVariable;
use yii\base\Event;
use yii\elasticsearch\Connection;
use yii\elasticsearch\DebugPanel;

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
 * @property  Settings               settings
 * @property  Connection             elasticsearch
 * @method    Settings getSettings()
 */
class Elasticsearch extends Plugin
{
    public $name = 'Elasticsearch';

    const APP_COMPONENT_NAME = 'elasticsearch';

    // Public Methods
    // =========================================================================


    public function init()
    {
        parent::init();

        $this->setComponents([
            'service' => ElasticsearchService::class,
        ]);

        /** @noinspection PhpUnhandledExceptionInspection */
        Craft::$app->set(self::APP_COMPONENT_NAME, [
            'class' => 'yii\elasticsearch\Connection',
            'nodes' => [
                ['http_address' => $this->settings->http_address],
                // configure more hosts if you have a cluster
            ],
            'auth'  => [
                'username' => $this->settings->auth_username,
                'password' => $this->settings->auth_password,
            ],
        ]);

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lhs\elasticsearch\console\controllers';
        }

        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('elasticsearch', ElasticsearchVariable::class);
            }
        );

        // Delete an element from the index
        Event::on(
            Element::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var entry $entry */
                $entry = $event->sender;
                $this->service->deleteEntry($entry);
            }
        );

        // Add or update an element to the index
        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function(Event $event) {
                $element = $event->sender;
                if ($element instanceof Entry) {
                    if ($element->enabled) {
                        Craft::$app->queue->push(new IndexElement([
                            'siteId'    => $element->siteId,
                            'elementId' => $element->id,
                        ]));
                    } else {
                        Elasticsearch::getInstance()->service->deleteEntry($element);
                    }
                }
            }
        );

        // Re-index all when plugin settings are saved
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
            function(PluginEvent $event) {
                if ($event->plugin === $this) {
                    Craft::debug('Elasticsearch plugin settings saved => re-index all entries', __METHOD__);
                    $this->service->reindexAll();
                }
            }
        );

        // Register the plugin's CP utility
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ElasticsearchUtilities::class;
            }
        );

        // Register CP permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions['elasticsearch'] = [
                    'reindex' => ['label' => Craft::t('elasticsearch', 'Refresh Elasticsearch index')],
                ];
            }
        );

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['elasticsearch/cp/test-connection'] = 'elasticsearch/cp/test-connection';
                $event->rules['elasticsearch/cp/reindex-perform-action'] = 'elasticsearch/cp/reindex-perform-action';
            }
        );

        // Add the Elasticsearch panel to the Yii debug bar
        Event::on(
            Application::class,
            Application::EVENT_BEFORE_REQUEST,
            function() {
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
    public static function getConnection()
    {
        /** @var Connection $connection */
        /** @noinspection PhpUnhandledExceptionInspection */
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
        opcache_reset();
        $settingsModel = new Settings();

        return $settingsModel;
    }

    public function setSettings(array $settings)
    {
        // Ensure all sites have a blacklist (at least an empty one)
        $siteIds = Craft::$app->sites->getAllSiteIds();
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
        array_map(function($section) use (&$sections) {
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
}
