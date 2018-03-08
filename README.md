# Elasticsearch plugin for Craft CMS 3.x

Bring the power of Elasticsearch to you Craft 3 CMS project

![Screenshot](resources/img/plugin-logo.png)

## Requirements

This plugin requires **Craft CMS 3.0.0-RC1** or later.

In order to index data, you will need an **Elasticsearch instance 6.0** or later with the Ingest attachment processor plugin activated.

### Installation

- Install with Composer from your project directory
```
composer require la-hautes-societe/craft-elasticsearch
```

- In the Control Panel, go to Settings → Plugins and click the “Install” button for Elasticsearch.
 
## Elasticsearch plugin Overview

Elasticsearch plugin will automatically index each entries on your site(s).

It will figure out the best Elasticsearch mapping for you based on your site(s) language. 

## Configuring Elasticsearch plugin

Go to the plugin settings and adjust the host name and port for your Elasticsearch instance.

If your instance is protected with X-Pack Security, you can provide your username and passwords as well.

Optionally, in the `config` folder, you can override the following plugin configurations by adding a `elacticsearch.php` file as follow:
```php
<?php
return [
    'content_pattern' => '/<main id="content".*?>(.*?)<\/main>/s',
    'highlight'       => [
        'pre_tags'  => '<strong>',
        'post_tags' => '</strong>',
    ]
];
```

- `content_pattern`: the regular expression used to extract the relevant content of the page to be indexed
- `highlight`: the elasticsearch configuration used to highlight query results. For more options, refer to the [elasticsearch documentation](https://www.elastic.co/guide/en/elasticsearch/reference/6.x/search-request-highlighting.html)

## Using Elasticsearch plugin

You can enable the search feature in your frontend templates by calling the `craft.elasticsearch.results('Something to search')` variable.
For instance, in a template `search/index.twig`, you could could use it like this:

```twig
{% set results = craft.elasticsearch.results(craft.app.request.get('q')) %}

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
* `id`: Unique ID of the result
* `title`: The page title
* `url`: The full url of the page
* `score`: The result score for the entry
* `highlights`: An array of highlighted contents based on the found terms from the query

## Elasticsearch plugin utilities

If your Elasticsearch index becomes out of sync with your sites contents, you can go to Utilities → Elasticsearch then click the "Reindex all" button.

## Elasticsearch plugin Roadmap

* Handel dependencies update 
* Handel multi-sites configurations
* Detect need for re-indexation

Brought to you by ![LHS Logo](resources/img/lhs.png) [La Haute Société](https://www.lahautesociete.com)
