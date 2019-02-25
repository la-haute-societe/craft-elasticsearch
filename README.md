[![Elastic](resources/img/elastic-logo.png)][elastic-site]  

# Elasticsearch plugin for Craft CMS 3.x

Bring the power of Elasticsearch to your Craft 3 CMS project.

![Plugin screenshot](resources/img/plugin-screenshot.png)



## Requirements

This plugin requires **Craft CMS 3.0.0-RC1** or later.

In order to index data, you will need an **Elasticsearch 6.0** (or later) 
instance, with the **Ingest attachment processor** plugin activated.


## Installation

### The easy way

Just install the plugin from the Craft Plugin Store.

### Using Composer

  - Install with Composer from your project directory: `composer require la-haute-societe/craft-elasticsearch`
  - In the Craft Control Panel, go to Settings → Plugins and click the **Install** button for Elasticsearch.
 

 
## Elasticsearch plugin Overview

Elasticsearch plugin will automatically index each entry on your site(s).

It will figure out the best Elasticsearch mapping for you based on your site(s)' language. 

## Supported languages

| Craft language | Elastic analyzer                | Notes                          |
| -------------- | ------------------------------- | ------------------------------ |
| ar             | arabic                          |                                |
| hy             | armenian                        |                                |
| eu             | basque                          |                                |
| bn             | bengali                         |                                |
| pt-BR          | brazilian                       |                                |
| bg             | bulgarian                       |                                |
| ca             | catalan                         |                                |
| zh             | cjk                             |                                |
| ja             | cjk                             |                                |
| ko             | cjk                             |                                |
| cs             | czech                           |                                |
| da             | danish                          |                                |
| nl             | dutch                           |                                |
| en             | english                         |                                |
| fi             | finnish                         |                                |
| fr             | french                          |                                |
| gl             | galician                        |                                |
| de             | german                          |                                |
| el             | greek                           |                                |
| hi             | hindi                           |                                |
| hu             | hungarian                       |                                |
| id             | indonesian                      |                                |
| ga             | irish                           |                                |
| it             | italian                         |                                |
| lv             | latvian                         |                                |
| lt             | lithuanian                      |                                |
| nb             | norwegian                       |                                |
| fa             | persian                         |                                |
| pt             | portuguese                      |                                |
| ro             | romanian                        |                                |
| ru             | russian                         |                                |
| es             | spanish                         |                                |
| pl             | stempel                         | analysis-stempel plugin needed |
| sv             | swedish                         |                                |
| tr             | turkish                         |                                |
| th             | thai                            |                                |




## Configuring the Elasticsearch plugin

You can configure the Elasticsearch plugin from the Craft Control Panel (some settings only), of from the 
_config/elasticsearch.php_ file in your Craft installation (all settings). If a setting is defined both in the CP and in
the configuration file, the latter takes precedence.

The [src/config.php](./src/config.php), file is a configuration template to be copied to _config/elasticsearch.php_.

> 💡 If you currently don't have an Elasticsearch server handy, here's how you can [set one up](./elasticsearch-quickstart.md).

### In both the configuration file and the CP

#### `elasticsearchEndpoint`
Type: _string_
 
The Elasticsearch instance endpoint URL (with protocol, host and port).
Ignored if `elasticsearchComponentConfig` is set.


#### `isAuthEnabled`
Type: _bool_
 
A boolean indicating whether authentication is required on the Elasticsearch instance.  
Ignored if `elasticsearchComponentConfig` is set.


#### `username`
Type: _string_
 
The username used to authenticate on the Elasticsearch instance if it's protected by 
X-Pack Security.  
Ignored if `isAuthEnabled` is set to `false` or `elasticsearchComponentConfig` is set.


#### `password`
Type: _string_
 
The password used to authenticate on the Elasticsearch instance if it's protected by
X-Pack Security.  
Ignored if `isAuthEnabled` is set to `false` or `elasticsearchComponentConfig` is set.


#### `highlight`
Type: _array_
 
The elasticsearch configuration used to highlight query results. Only `pre_tags` and 
`post_tags` are configurable in the CP, advanced config must be done in the file.  
For more options, refer to the [elasticsearch documentation][].


#### `blacklistedEntryTypes`
Type: _int[]_

An array of entry type ids of which entries should not be indexed.


### Only in the configuration file

#### `allowedIPs`
Type: _string[]_

An array of IP addresses allowed to use the Elasticsearch console commands.


#### `allowedHosts`
Type: _string[]_

An array of hostnames allowed to use the Elasticsearch console commands.


#### `contentExtractorCallback`
Type: _callable_

A callback (`function(string $entryContent): string`) used to extract the
content to be indexed from the full HTML source of the entry's page. 

The default is to extract the HTML code between those 2 comments: 
`<!-- BEGIN elasticsearch indexed content -->` and `<!-- END elasticsearch indexed content -->`.


#### `elasticsearchComponentConfig`
Type: _array_

An associative array passed to the yii2-elasticsearch component Connection class constructor.  
All public properties of the [yii2-elasticsearch component Connection class][yii2-elasticsearch] 
can be set.  
If this is set, the `elasticsearchEndpoint`, `username`, `password` and `isAuthEnabled` settings 
will be ignored.

     
     
[elasticsearch documentation]: https://www.elastic.co/guide/en/elasticsearch/reference/6.x/search-request-highlighting.html
[yii2-elasticsearch]: https://www.yiiframework.com/extension/yiisoft/yii2-elasticsearch/doc/api/2.1/yii-elasticsearch-connection#properties


## Indexable content

By default, the content indexed in each entry is between the `<!-- BEGIN elasticsearch indexed content -->` 
and `<!-- END elasticsearch indexed content -->` HTML comments in the source of the entry page.

If you're using semantic HTML in your templates, then putting your `<main>` or `<article>` element between 
those comments should be ideal. 

If you need more control over what is indexed, you'll have to set up a custom `contentExtractorCallback`.


## Running a search

The search feature can be used from a frontend template file by calling the 
`craft.elasticsearch.search('Something to search')` variable.
For instance, in a template `search/index.twig`:

```twig
{% set results = craft.elasticsearch.search(craft.app.request.get('q')) %}

{% block content %}
    <h1>{{ "Search"|t }}</h1>

    <form action="{{ url('search') }}">
        <input type="search" name="q" placeholder="Search" value="{{ craft.app.request.get('q') }}">
        <input type="submit" value="Go">
    </form>

    {% if results|length %}
        <h2>{{ "Results"|t }}</h2>

        {% for result in results %}
            <h3>{{ result.title }}</h3>
            <p>
                <small><a href="{{ result.url|raw }}">{{ result.url }}</a><br/>
                    {% if result.highlights|length %}
                        {% for highligh in result.highlights %}
                            {{ highligh|raw }}<br/>
                        {% endfor %}
                    {% endif %}
                </small>
            </p>
            <hr>
        {% endfor %}
    {% else %}
        {% if craft.app.request.get('q') is not null %}
            <p>
                <em>{{ "No results"|t }}</em>
            </p>
        {% endif %}
    {% endif %}
{% endblock %}
```

Each entry consists of the following attributes:

  - `id`: unique ID of the result
  - `title`: page title
  - `url`: full url to the page
  - `score`: entry result score
  - `highlights`: array of highlighted content matching the query terms



## Auto indexing

The plugin automatically indexes entries (created, updated or removed), as long as they're not in a blacklisted entry types,
or disabled.


All entries are reindexed (in the background) when plugin settings are saved.



## Elasticsearch plugin utilities

If your Elasticsearch index becomes out of sync with your sites contents, you 
can go to Utilities → Elasticsearch then click the **Reindex all** button.



## Elasticsearch plugin console commands

The plugin provides an extension to the Craft console command that lets you reindex all entries or recreate empty 
indexes.


### Recreate empty indexes

Remove index & create an empty one for all sites

````sh
./craft elasticsearch/elasticsearch/recreate-empty-indexes
````

Reindex all sites 

````sh
./craft elasticsearch/elasticsearch/reindex-all http://example.com
````


## About yii2-elasticsearch library

At the time of the release of this plugin, no stable 2.1 version of the `yiisoft/yii2-elasticsearch` was released yet. However, that version is mandatory in order to connect to Elasticsearch instances 5 or upper.

To simplify the installation process, a `la-haute-societe/yii2-elasticsearch` fork was made and flagged as stable. Please, note that **this fork was tested and intended to work with this plugin only**.


## Licensing

This plugin is free to try in development environments, but requires payment to be used in production environments. The license fee for this plugin is $99 (license + one year of updates) via the Craft Plugin Store, then $29 per year for updates (optional).


## Elasticsearch plugin Roadmap

* Craft Commerce products support
* Handle dependencies update 
* Detect need for re-indexation
* Switch form `la-haute-societe/yii2-elasticsearch` to official 2.1 `yiisoft/yii2-elasticsearch` library as soon as a stable version will be released

Brought to you by [![LHS Logo](resources/img/lhs.png) La Haute Société][lhs-site].

Elasticsearch is a trademark of Elasticsearch BV, registered in the U.S. and in
other countries.

[lhs-site]: https://www.lahautesociete.com
[elastic-site]: https://www.elastic.co/brand
