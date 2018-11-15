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
use yii\base\InvalidConfigException;

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

    /** @var string The Elasticsearch instance endpoint URL (with protocol, host and port) */
    public $elasticsearchEndpoint = 'elasticsearch:9200';

    /** @var string [optional] The username used to connect to the Elasticsearch server */
    public $username = 'elastic';

    /** @var string [optional] The password used to connect to the Elasticsearch server */
    public $password = 'MagicWord';

    /** @var bool A boolean indicating whether authentication to the Elasticsearch server is required */
    public $isAuthEnabled = false;

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

    /**
     * @var array An associative array passed to the yii2-elasticsearch component Connection class constructor.
     * @note If this is set, the $elasticsearchEndpoint, $username, $password and $isAuthEnabled properties will be ignored.
     * @see  https://www.yiiframework.com/extension/yiisoft/yii2-elasticsearch/doc/api/2.1/yii-elasticsearch-connection#properties
     */
    public $elasticsearchComponentConfig;

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
            ['elasticsearchEndpoint', 'required', 'message' => Craft::t(Elasticsearch::TRANSLATION_CATEGORY, 'Endpoint URL is required')],
            ['elasticsearchEndpoint', 'url', 'defaultScheme' => 'http', 'pattern' => '/^{schemes}:\/\/(([A-Z0-9][A-Z0-9_-]*)(\.?[A-Z0-9][A-Z0-9_-]*)+)(?::\d{1,5})?(?:$|[?\/#])/i'],
            ['elasticsearchEndpoint', 'default', 'value' => 'elasticsearch.example.com:9200'],
            ['isAuthEnabled', 'boolean'],
            [['username', 'password'], 'string'],
            [['username', 'password'], 'trim'],
        ];
    }

    public function afterValidate()
    {
        if ($this->elasticsearchComponentConfig !== null) {
            parent::afterValidate();
            return;
        }

        // Save the current Elasticsearch connector
        $previousElasticConnector = Craft::$app->get(Elasticsearch::APP_COMPONENT_NAME);

        // Create a new instance of the Elasticsearch connector with the freshly-submitted url and auth settings
        $elasticsearchPlugin = Elasticsearch::getInstance();
        assert($elasticsearchPlugin !== null, "Elasticsearch::getInstance() should always return the plugin instance when called from the plugin's code.");

        try {
            $elasticsearchPlugin->initializeElasticConnector($this);

            // Run the actual validation
            if (!$elasticsearchPlugin->service->testConnection()) {
                throw new InvalidConfigException('Could not connect to the Elasticsearch server.');
            }

            // Clean up the mess we made to run the validation
            Craft::$app->set(Elasticsearch::APP_COMPONENT_NAME, $previousElasticConnector);
        } catch (InvalidConfigException $e) {
            $this->addError('global', Craft::t(
                Elasticsearch::TRANSLATION_CATEGORY,
                'Could not connect to the Elasticsearch instance at {elasticsearchEndpoint}. Please check the endpoint URL and authentication settings.',
                ['elasticsearchEndpoint' => $this->elasticsearchEndpoint]
            ));
        }

        parent::afterValidate();
    }
}
