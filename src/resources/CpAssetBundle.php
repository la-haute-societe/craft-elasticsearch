<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\resources;


use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * AssetBundle used in the Control Panel
 */
class CpAssetBundle extends AssetBundle
{
    /**
     * Initializes the bundle.
     */
    public function init()
    {
        // define the path that your publishable resources live
        $this->sourcePath = '@lhs/elasticsearch/resources/cp';

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        // define the relative path to CSS/JS files that should be registered
        // with the page when this asset bundle is registered
        $this->js = [
            'js/utilities/reindex.js',
        ];

        $this->css = [
            'css/elastic-branding.css',
        ];

        parent::init();
    }
}
