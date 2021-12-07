<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

/**
 * Elasticsearch config.php
 *
 * This file exists only as a template for the Elasticsearch settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'elasticsearch.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

return [
    'elasticsearchEndpoint'        => 'https://long-hash.eu-central-1.aws.cloud.example.com:9243',
    'isAuthEnabled'                => true,
    'username'                     => 'elastic',
    'password'                     => 'password',
    'indexNamePrefix' => '',

    'highlight' => [
        'pre_tags'  => '<strong>',
        'post_tags' => '</strong>',
    ],

    'blacklistedEntryTypes' => [],
    'blacklistedAssetVolumes' => [],

    'contentExtractorCallback' => function (string $entryContent) {
        if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $entryContent, $body)) {
            $entryContent = '<!DOCTYPE html>' . trim($body[1]);
        }

        return $entryContent;
    },

    // 'elementContentCallback' => function (\craft\base\ElementInterface $element) {
    //     return '<span>Some HTML element content to index</span>';
    // },

    // 'resultFormatterCallback'  => function (array $formattedResult, $result) {
    //         // Do something
    // },


    // The `elasticsearchEndpoint`, `username`, `password` and `isAuthEnabled` settings are ignored if this is set
    'elasticsearchComponentConfig' => [
        'autodetectCluster' => false,
        'defaultProtocol'   => 'http',

        'nodes' => [
            [
                'protocol'     => 'https',
                'http_address' => 'long-hash.eu-central-1.aws.cloud.example.com:9243',
            ],
        ],

        'auth' => [
            'username' => 'elastic',
            'password' => 'password',
        ],

        'connectionTimeout' => 10,
        'dataTimeout'       => 30,
    ],

   // 'extraFields'              => [
   //     'fieldOne' => [
   //         'mapping'     => [
   //             'type'  => 'text',
   //             'analyzer' => 'standard',
   //             'store' => true
   //         ],
   //         'highlighter' => (object)['type' => 'plain'],
   //         'value'       => function (\craft\base\ElementInterface $element, \lhs\elasticsearch\records\ElasticsearchRecord $esRecord) {
   //                 // Return something
   //         }
   //     ]
   // ]
];
