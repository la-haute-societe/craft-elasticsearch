<?php

namespace lhs\elasticsearch\models;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Entry;
use lhs\elasticsearch\exceptions\IndexableElementModelException;

/**
 *
 * @property-read Element $element
 */
class IndexableElementModel extends \craft\base\Model implements \JsonSerializable
{
    public $elementId;
    public $siteId;
    public $type;

    /**
     * @return Element
     * @throws IndexableElementModelException
     */
    public function getElement()
    {
        switch ($this->type) {
            case Product::class:
                $commercePlugin = craft\commerce\Plugin::getInstance();
                if (!$commercePlugin) {
                    throw new IndexableElementModelException($this, IndexableElementModelException::CRAFT_COMMERCE_NOT_INSTALLED);
                }
                $element = $commercePlugin->getProducts()->getProductById($this->elementId, $this->siteId);
                break;
            case DigitalProduct::class:
                $digitalProductsPlugin = craft\digitalproducts\Plugin::getInstance();
                if (!$digitalProductsPlugin) {
                    throw new IndexableElementModelException($this, IndexableElementModelException::DIGITAL_PRODUCTS_NOT_INSTALLED);
                }
                $element = Craft::$app->getElements()->getElementById($this->elementId, DigitalProduct::class, $this->siteId);
                break;
            case Entry::class:
                $element = Craft::$app->getEntries()->getEntryById($this->elementId, $this->siteId);
                break;
            case Asset::class:
                $element = Craft::$app->getAssets()->getAssetById($this->elementId, $this->siteId);
                break;
            default:
                throw new IndexableElementModelException($this, IndexableElementModelException::UNEXPECTED_TYPE);
        }

        if ($element === null) {
            throw new IndexableElementModelException($this, IndexableElementModelException::ELEMENT_NOT_FOUND);
        }

        return $element;
    }


    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
