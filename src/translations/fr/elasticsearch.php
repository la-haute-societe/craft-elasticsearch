<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 Alban Jubert
 */

/**
 * Elasticsearch en Translation
 *
 * Returns an array with the string to be translated (as passed to `Craft::t('elasticsearch', '...')`) as
 * the key, and the translation as the value.
 *
 * http://www.yiiframework.com/doc-2.0/guide-tutorial-i18n.html
 *
 * @author    Alban Jubert
 * @package   Elasticsearch
 * @since     1.0.0
 */
return [
    'Host'                                                           => 'Hôte',
    'Auth username'                                                  => 'Nom d\'utilisateur',
    'Auth password'                                                  => 'Mot de passe',
    'Host is required'                                               => 'L\'hôte est obligatoire',
    'Test connection'                                                => 'Tester la connexion',
    'Sites'                                                          => 'Sites',
    'All sites'                                                      => 'Tous les sites',
    'Reindex selected'                                               => 'Réindexer les sites sélectionnés',
    'Successfully connected to {http_address}'                       => 'Connecté à {http_address} avec succès',
    'Could not establish connection with {http_address}'             => 'Impossible d\'établir la connexion avec {http_address}',
    'Index a page in Elasticsearch'                                  => 'Indexation d\'une page dans Elasticsearch',
    'Elasticsearch indexing in progress...'                          => 'Indexation Elasticsearch en cours...',
    'Authorizations parameters (optional)'                           => 'Paramètres d\'autorisations (facultatif)',
    'Elasticsearch hostname or IP and port (ie. elasticsearch:9200)' => 'Nom d\'hôte ou IP et port de l\'instance Elasticsearch (ex. elasticsearch:9200)',
    'Elasticsearch index is out of sync!'                            => 'L\'index Elasticsearch n\'est plus synchronisé !',
    'Refresh ElasticSearch index'                                    => 'Rafraîcher l\'index ElasticSearch',
];
