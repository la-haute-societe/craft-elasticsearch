<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\resources\CpAssetBundle;

/**
 * A Control Panel utility to force reindexing site entries
 */
class RefreshElasticsearchIndexUtility extends Utility
{
    /**
     * Returns the display name of this utility.
     * @return string The display name of this utility.
     */
    public static function displayName(): string
    {
        return Craft::t(Elasticsearch::TRANSLATION_CATEGORY, 'Refresh Elasticsearch index');
    }

    /**
     * Returns the utility’s unique identifier.
     * The ID should be in `kebab-case`, as it will be visible in the URL (`admin/utilities/the-handle`).
     *
     * @return string
     */
    public static function id(): string
    {
        return 'refresh-elasticsearch-index';
    }

    /**
     * Returns the path to the utility's SVG icon.
     * @return string|null The path to the utility SVG icon
     */
    public static function iconPath()
    {
        return Craft::getAlias('@lhs/elasticsearch/resources/cp/img/utility-icon.svg');
    }

    /**
     * Returns the number that should be shown in the utility’s nav item badge.
     * If `0` is returned, no badge will be shown
     * @return int
     */
    public static function badgeCount(): int
    {
        try {
            if (!Elasticsearch::getInstance()->service->testConnection() || !Elasticsearch::getInstance()->service->isIndexInSync()) {
                return 1;
            }
        } catch (\Exception $e) {
            // Noop, we sure don't want to break the Control Panel over a badge count…
        }

        return 0;
    }

    /**
     * Returns the utility's content HTML.
     * @return string
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();

        $view->registerAssetBundle(CpAssetBundle::class);
        $view->registerJs('new Craft.ElasticsearchUtility(\'elasticsearch-utility\');');

        $isConnected = Elasticsearch::getInstance()->service->testConnection();
        $inSync = Elasticsearch::getInstance()->service->isIndexInSync();

        $sites = ArrayHelper::map(Craft::$app->sites->getAllSites(), 'id', 'name');

        return Craft::$app->getView()->renderTemplate(
            'elasticsearch/cp/utility',
            [
                'isConnected'                => $isConnected,
                'inSync'                     => $inSync,
                'sites'                      => $sites,
                'notConnectedWarningMessage' => Craft::t(Elasticsearch::TRANSLATION_CATEGORY, 'Could not connect to the elasticsearch instance. Please check the {pluginSettingsLink}.', [
                    'pluginSettingsLink' => sprintf(
                        '<a href="%s">%s</a>',
                        UrlHelper::cpUrl('/settings/plugins/' . Elasticsearch::APP_COMPONENT_NAME),
                        Craft::t(Elasticsearch::TRANSLATION_CATEGORY, 'plugin\'s settings')
                    ),
                ]),
            ]
        );
    }
}
