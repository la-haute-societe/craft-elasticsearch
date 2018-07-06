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
    // Settings
    'Host'                                                                                   => 'Hôte',
    'Host is required'                                                                       => 'L\'hôte est obligatoire',
    'Elasticsearch hostname or IP and port (ie. elasticsearch:9200)'                         => 'Nom d\'hôte ou IP et port de l\'instance Elasticsearch (ex. elasticsearch:9200)',
    'Authorizations parameters (optional)'                                                   => 'Paramètres d\'autorisations (facultatif)',
    'Username'                                                                               => 'Nom d\'utilisateur',
    'Password'                                                                               => 'Mot de passe',
    'Search term highlight'                                                                  => 'Mise en avant des résultats',
    'HTML tags used to wrap the search term in order to highlight it in  the search results' => 'Balises HTML insérées autour des termes recherchés afin de les mettre en avant dans les résultats de recherche',
    'Before'                                                                                 => 'Avant',
    'After'                                                                                  => 'Après',


    // Utility
    'Elasticsearch index is out of sync!'                                                    => 'L\'index Elasticsearch n\'est plus synchronisé !',
    'Reindex selected'                                                                       => 'Réindexer les sites sélectionnés',
    'Sites'                                 => 'Sites',
    'Could not connect to the elasticsearch instance. Please check your settings'            => 'Connexion à l\'instance Elasticsearch impossible. Veuillez vérifier les paramètres du plugin.',

    // Connection test
    'Successfully connected to {http_address}'                                               => 'Connecté à {http_address} avec succès',
    'Could not establish connection with {http_address}'                                     => 'Impossible d\'établir la connexion avec {http_address}',


    // Jobs
    'Index a page in Elasticsearch'         => 'Indexation d\'une page dans Elasticsearch',


    // Permissions
    'Refresh Elasticsearch index'           => 'Rafraîcher l\'index Elasticsearch',
];
