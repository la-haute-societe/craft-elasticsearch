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
use lhs\elasticsearch\assetbundles\elasticsearchutility\ElasticsearchUtilityAsset;
use lhs\elasticsearch\Elasticsearch;

/**
 * Elasticsearch Utility
 *
 * Utility is the base class for classes representing Control Panel utilities.
 *
 * https://craftcms.com/docs/plugins/utilities
 *
 * @author    La Haute Société
 * @package   Elasticsearch
 * @since     1.0.0
 */
class ElasticsearchUtilities extends Utility
{
    // Static
    // =========================================================================

    /**
     * Returns the display name of this utility.
     *
     * @return string The display name of this utility.
     */
    public static function displayName(): string
    {
        return Craft::t('elasticsearch', 'Elasticsearch');
    }

    /**
     * Returns the utility’s unique identifier.
     *
     * The ID should be in `kebab-case`, as it will be visible in the URL (`admin/utilities/the-handle`).
     *
     * @return string
     */
    public static function id(): string
    {
        return 'elasticsearch-utilities';
    }

    /**
     * Returns the path to the utility's SVG icon.
     *
     * @return string|null The path to the utility SVG icon
     */
    public static function iconPath()
    {
        return Craft::getAlias("@lhs/elasticsearch/assetbundles/elasticsearchutility/dist/img/icon.svg");
    }

    /**
     * Returns the number that should be shown in the utility’s nav item badge.
     *
     * If `0` is returned, no badge will be shown
     *
     * @return int
     */
    public static function badgeCount(): int
    {
        return 0;
    }

    /**
     * Returns the utility's content HTML.
     *
     * @return string
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public static function contentHtml(): string
    {
        Craft::$app->getView()->registerAssetBundle(ElasticsearchUtilityAsset::class);

        $inSync = Elasticsearch::$plugin->elasticsearch->isIndexInSync();

        return Craft::$app->getView()->renderTemplate(
            'elasticsearch/_components/utilities/Elasticsearch_content',
            [
                'inSync' => $inSync
            ]
        );
    }
}
