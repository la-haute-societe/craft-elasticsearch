<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

namespace lhs\elasticsearch\models;

use Craft;
use craft\base\Model;
use lhs\elasticsearch\Elasticsearch;

/**
 * Elasticsearch Settings Model
 *
 * This is a model used to define the plugin's settings.
 *
 * Models are containers for data. Just about every time information is passed
 * between services, controllers, and templates in Craft, it’s passed via a model.
 *
 * https://craftcms.com/docs/plugins/models
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * Some model attribute
     *
     * @var string
     */
    public $http_address = 'elasticsearch:9200';
    public $auth_username = 'elastic';
    public $auth_password = 'MagicWord';
    public $content_pattern;
    public $highlight = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    public function rules()
    {
        return [
            ['http_address', 'required', 'message' => Craft::t(Elasticsearch::TRANSLATION_CATEGORY, 'Host is required')],
            ['http_address', 'string'],
            ['http_address', 'default', 'value' => 'elasticsearch:9200'],
            [['auth_username', 'auth_password'], 'string'],
            [['auth_username', 'auth_password'], 'trim'],
        ];
    }
}
