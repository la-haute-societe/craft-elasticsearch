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
use craft\services\Plugins;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use lhs\elasticsearch\jobs\DeleteElement;
use lhs\elasticsearch\jobs\IndexElement;
use lhs\elasticsearch\jobs\SomeJob;
use lhs\elasticsearch\models\Settings;
use lhs\elasticsearch\utilities\ElasticsearchUtilities;
use lhs\elasticsearch\variables\ElasticsearchVariable;
use yii\base\Event;
use yii\elasticsearch\Connection;

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
 * @property  Elasticsearch $elasticsearch
 * @property  Settings $settings
 * @property  Connection $connection
 * @method    Settings getSettings()
 */
class Elasticsearch extends Plugin
{
    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Elasticsearch::$plugin
     *
     * @var Elasticsearch
     */
    public static $plugin;

    // Static Properties
    // =========================================================================

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Elasticsearch::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        $this->name = "Elasticsearch";
        self::$plugin = $this;

        $this->setComponents([
            'connection' => [
                'class' => 'yii\elasticsearch\Connection',
                'nodes' => [
                    ['http_address' => $this->settings->http_address],
                    // configure more hosts if you have a cluster
                ],
                'auth'  => [
                    'username' => $this->settings->auth_username,
                    'password' => $this->settings->auth_password
                ]
            ]
        ]);

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lhs\elasticsearch\console\controllers';
        }

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['elasticsearch/test-connection'] = 'elasticsearch/elasticsearch/test-connection';
                $event->rules['elasticsearch/reindex-all'] = 'elasticsearch/elasticsearch/reindex-all';
            }
        );

        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('elasticsearch', ElasticsearchVariable::class);
            }
        );

        /*
         * Add or update an element to the index
         */
        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function (Event $event) {
                $element = $event->sender;
                if ($element instanceof Entry) {
                    if ($element->enabled) {
                        Craft::$app->queue->push(new IndexElement([
                            'siteId'    => $element->siteId,
                            'elementId' => $element->id
                        ]));
                    } else {
                        Elasticsearch::$plugin->elasticsearch->deleteEntry($element);
                    }

                }
            }
        );

        /*
         * Delete an element from the index
         */
        Event::on(
            Element::class,
            Element::EVENT_AFTER_DELETE,
            function (Event $event) {
                $element = $event->sender;
                Elasticsearch::$plugin->elasticsearch->deleteEntry($element);
            }
        );

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ElasticsearchUtilities::class;
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        Craft::info(
            Craft::t(
                'elasticsearch',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
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
        return new Settings();
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
        return Craft::$app->view->renderTemplate(
            'elasticsearch/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

}
