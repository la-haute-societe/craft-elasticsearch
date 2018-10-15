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

    /** @var string The hostname and port (separated by a colon `:`) used to connect to the Elasticsearch server */
    public $http_address = 'elasticsearch:9200';

    /** @var string [optional] The username used to connect to the Elasticsearch server */
    public $auth_username = 'elastic';

    /** @var string [optional] The password used to connect to the Elasticsearch server */
    public $auth_password = 'MagicWord';

    /**
     * @var callable A callback used to extract the indexable content from a page source code.
     *               The only argument is the page source code (rendered template) and it is expected to return a string.
     */
    public $contentExtractorCallback;

    /** @var array The tags inserted before and after the search term to highlight in search results */
    public $highlight = [
        'pre_tags'  => null,
        'post_tags' => null,
    ];

    /** @var array The list of IDs of sections which entries should not be indexed */
    public $blacklistedSections = [];

    /** @var array The list of hosts that are allowed to access this module. */
    public $allowedHosts = ['localhost'];

    /** @var array The list of IPs that are allowed to access this module. */
    public $allowedIPs = ['::1', '127.0.0.1'];

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
