<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\models;

use Craft;
use craft\base\Model;
use lhs\elasticsearch\Elasticsearch;
use yii\base\InvalidConfigException;

/**
 * Define the plugin's settings.
 */
class SettingsModel extends Model
{
    /** @var string The Elasticsearch instance endpoint URL (with protocol, host and port) */
    public $elasticsearchEndpoint = 'elasticsearch:9200';

    /** @var bool A boolean indicating whether authentication to the Elasticsearch server is required */
    public $isAuthEnabled = false;

    /** @var string [optional] The username used to connect to the Elasticsearch server */
    public $username = 'elastic';

    /** @var string [optional] The password used to connect to the Elasticsearch server */
    public $password = 'MagicWord';

    /** @var string [optional] Index name prefix used to avoid index name collision when using a single Elasticsearch
     *                         instance with several Craft instances.
     *                         Up to 5 characters, all lowercase.
     */
    public $indexNamePrefix;

    /** @var array A list of handles of entries types that should not be indexed */
    public $blacklistedEntryTypes = [];

    /** @var array A list of handles of asset volumes that should not be indexed */
    public $blacklistedAssetVolumes = [];

    /**
     * @var callable A callback used to extract the indexable content from a page source code.
     *               The only argument is the page source code (rendered template) and it is expected to return a string.
     */
    public $contentExtractorCallback;

    /**
     * @var callable A callback used to get the HTML content of the element to index.
     *               If null, the default Guzzle Client implementation will be used instead to get the content.
     */
    public $elementContentCallback;

    /**
     * @var callable A callback used to prepare and format the Elasticsearch result object in order to be used by the results twig view.
     *               Expect two arguments: first, an array represented initial formatted results, the second, a Elasticsearch record result object.
     */
    public $resultFormatterCallback;

    /** @var array The tags inserted before and after the search term to highlight in search results */
    public $highlight = [
        'pre_tags'  => '',
        'post_tags' => '',
    ];

    /**
     * @var array An associative array passed to the yii2-elasticsearch component Connection class constructor.
     * @note If this is set, the $elasticsearchEndpoint, $username, $password and $isAuthEnabled properties will be ignored.
     * @see  https://www.yiiframework.com/extension/yiisoft/yii2-elasticsearch/doc/api/2.1/yii-elasticsearch-connection#properties
     */
    public $elasticsearchComponentConfig;

    /**
     * @var array An associative array defining additional fields to be indexed along with the defaults one.
     * Each additional field should be declared as the name of the attribute as the key and an associative array for the value
     * in which the keys can be:
     * - `mapping`: an array providing the elasticsearch mapping definition for the field. For example:
     *   ```php
     *   [
     *        'type'  => 'text',
     *        'store' => true
     *   ]
     *   ```
     * - `highlighter` : an object defining the elasticsearch highlighter behavior for the field. For example: `(object)[]`
     * - `value` : either a string or a callable function taking one argument of \craft\base\Element type and returning the value of the field, for example:
     *   ```php
     *   function (\craft\base\Element $element) {
     *       return ArrayHelper::getValue($element, 'color.hex');
     *   }
     *   ```
     */
    public $extraFields = [];

    /**
     * Returns the validation rules for attributes.
     * @return array
     */
    public function rules(): array
    {
        return [
            ['elasticsearchEndpoint', 'required', 'message' => Craft::t(Elasticsearch::PLUGIN_HANDLE, 'Endpoint URL is required')],
            ['elasticsearchEndpoint', 'url', 'defaultScheme' => 'http', 'pattern' => '/^{schemes}:\/\/(([A-Z0-9][A-Z0-9_-]*)(\.?[A-Z0-9][A-Z0-9_-]*)+)(?::\d{1,5})?(?:$|[?\/#])/i'],
            ['elasticsearchEndpoint', 'default', 'value' => 'elasticsearch.example.com:9200'],
            ['isAuthEnabled', 'boolean'],
            [['username', 'password'], 'string'],
            [['username', 'password'], 'trim'],
            [['blacklistedEntryTypes', 'highlight'], 'safe'],
            ['indexNamePrefix', 'string', 'max' => 5],
            ['indexNamePrefix', 'match', 'pattern' => '/^[a-z]+$/', 'message' => Craft::t('elasticsearch', 'Only lowercase letters are allowed.')],
        ];
    }

    public function afterValidate()
    {
        if ($this->elasticsearchComponentConfig !== null) {
            parent::afterValidate();
            return;
        }

        // Save the current Elasticsearch connector
        $previousElasticConnector = Craft::$app->get(Elasticsearch::PLUGIN_HANDLE);

        // Create a new instance of the Elasticsearch connector with the freshly-submitted url and auth settings
        $elasticsearchPlugin = Elasticsearch::getInstance();
        assert($elasticsearchPlugin !== null, "Elasticsearch::getInstance() should always return the plugin instance when called from the plugin's code.");

        try {
            $elasticsearchPlugin->initializeElasticConnector($this);

            // Run the actual validation
            if (!$elasticsearchPlugin->service->testConnection()) {
                throw new InvalidConfigException('Could not connect to the Elasticsearch server.');
            }
        } catch (InvalidConfigException $e) {
            $this->addError(
                'global',
                Craft::t(
                    Elasticsearch::PLUGIN_HANDLE,
                    'Could not connect to the Elasticsearch instance at {elasticsearchEndpoint}. Please check the endpoint URL and authentication settings.',
                    ['elasticsearchEndpoint' => $this->elasticsearchEndpoint]
                )
            );
        } finally {
            // Clean up the mess we made to run the validation
            /** @noinspection PhpUnhandledExceptionInspection Shouldn't happen as the component to set is already initialized */
            Craft::$app->set(Elasticsearch::PLUGIN_HANDLE, $previousElasticConnector);
        }

        // Cleanup blacklistedEntryTypes to remove empty values
        $this->blacklistedEntryTypes = array_filter(
            $this->blacklistedEntryTypes,
            function ($value) {
                return !empty($value);
            }
        );

        parent::afterValidate();
    }
}
