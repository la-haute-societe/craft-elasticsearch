<?php
/**
 * @author Yohann Bianchi<yohann.b@lahautesociete.com>
 * @date 21/06/2018 17:04
 */

namespace lhs\elasticsearch\resources;


use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * CpAssetBundle
 *
 * AssetBundle represents a collection of asset files, such as CSS, JS, images.
 *
 * Each asset bundle has a unique name that globally identifies it among all asset bundles used in an application.
 * The name is the [fully qualified class name](http://php.net/manual/en/language.namespaces.rules.php)
 * of the class representing it.
 *
 * An asset bundle can depend on other asset bundles. When registering an asset bundle
 * with a view, all its dependent asset bundles will be automatically registered.
 *
 * http://www.yiiframework.com/doc-2.0/guide-structure-assets.html
 *
 * @author    La Haute Société
 * @package   Elasticsearch
 * @since     1.0.0
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
