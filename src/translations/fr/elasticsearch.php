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
 * Elasticsearch french translation
 *
 * Returns an array with the string to be translated (as passed to `Craft::t('elasticsearch', '...')`) as
 * the key, and the translation as the value.
 */
return [
    // Settings
    'Elasticsearch connection'                                                                                                               => 'Connexion à Elasticsearch',
    'Could not connect to the Elasticsearch instance at {elasticsearchEndpoint}. Please check the endpoint URL and authentication settings.' => "Impossible de se connecter a l'instance Elasticsearch {elasticsearchEndpoint}. Veuillez vérifier le nom d'hôte et les paramètres d'authentification.",
    'These settings will be ignored since <code>elasticsearchComponentConfig</code> is present in the configuration file.'                   => 'Ces paramètre seront ignorés parce que <code>elasticsearchComponentConfig</code> est présent dans le fichier de configuration.',
    'Elasticsearch endpoint URL'                                                                                                             => "URL de l'instance Elasticsearch",
    'Endpoint URL is required'                                                                                                               => "L'URL de l'instance Elasticsearch est obligatoire",
    'Authentication required'                                                                                                                => 'Authentification requise',
    'The URL of the Elasticsearch instance (ie. elastic.example.com:9200)'                                                                   => "URL de l'instance Elasticsearch (ex. elastic.example.com:9200)",
    'Username'                                                                                                                               => "Nom d'utilisateur",
    'Password'                                                                                                                               => 'Mot de passe',
    'Search term highlight'                                                                                                                  => 'Mise en avant des résultats',
    'HTML tags used to wrap the search term in order to highlight it in  the search results' => 'Balises HTML insérées autour des termes recherchés afin de les mettre en avant dans les résultats de recherche',
    'Before'                                                                                 => 'Avant',
    'After'                                                                                  => 'Après',
    'Blacklisted entries types'                                                              => 'Types d\'entrées exclues',
    'Never index:'                                                                           => 'Ne pas indexer :',
    'Entry Type'                                                                             => 'Type d\'entrée',


    // Utility
    'Refresh Elasticsearch index'                                                            => "Rafraîchir l'index Elasticsearch",
    'Elasticsearch index is out of sync!'                                                    => "L'index Elasticsearch n'est plus synchronisé !",
    'Reindex selected'                                                                       => 'Réindexer les sites sélectionnés',
    'Sites'                                                                                                                                  => 'Sites',
    'Could not connect to the elasticsearch instance. Please check the {pluginSettingsLink}.'                                                => "Connexion à l'instance Elasticsearch impossible. Veuillez vérifier les {pluginSettingsLink}.",
    "plugin's settings"                                                                                                                      => 'paramètres du plugin',

    // Connection test
    'Successfully connected to {elasticsearchEndpoint}'                                                                                      => 'Connecté à {elasticsearchEndpoint} avec succès',
    'Could not establish connection with {elasticsearchEndpoint}'                                                                            => "Impossible d'établir la connexion avec {elasticsearchEndpoint}",


    // Jobs
    'Index a page in Elasticsearch'                                                                                                          => "Indexation d'une page dans Elasticsearch",


    // Exceptions
    'Cannot reindex entry {entryUrl}: {previousExceptionMessage}'                                                                            => "Impossible de réindexer l'entrée {entryUrl}: {previousExceptionMessage}",
    'Cannot fetch the id of the current site. Please make sure at least one site is enabled.'                                                => "Impossible de récupérer l'id du site actuel. Assurez-vous qu'au moins un site est activé.",
    'An error occurred while running the "{searchQuery}" search query on Elasticsearch instance: {previousExceptionMessage}'                 => "Une erreur s'est produite lors de l'exécution de la recherche \"{searchQuery}\" sur l'instance Elasticsearch : {previousExceptionMessage}",
    'Invalid site id: {siteId}'                                                                                                              => 'Id de site invalide: {siteId}',
    'The entry #{entryId} has an incorrect section id: #{sectionId}'                                                                         => "L'entrée #{entryId} a un id de section incorrect: #{sectionId}",
    'The entry #{entryId} uses an invalid Twig template: {twigTemplateName}'                                                                 => "L'entrée #{entryId} utilise un template Twig invalide: {twigTemplateName}",
    'An error occurred while rendering the {twigTemplateName} Twig template: {previousExceptionMessage}'                                     => "Une erreur s'est produite pendant le rendu du template Twig {twigTemplateName} : {previousExceptionMessage}",
    'Cannot recreate empty indexes for all sites'                                                                                            => 'Impossible de recréer des index Elasticsearch vides pour tous les sites',
    'No such entry (entry #{entryId} / site #{siteId}'                                                                                       => 'Aucune entrée correspondante (entrée #{entryId} / site #{siteId}',
];
