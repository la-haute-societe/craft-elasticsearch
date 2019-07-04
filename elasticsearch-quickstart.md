# Elasticsearch quickstart

The craft-elasticsearch plugin isn't very useless without an Elasticsearch server to connect to.

In this guide we'll walk you through the steps to set up both a development and a production Elasticsearch instance.


## Development using DDEV

A super easy way to get up and running is by using [DDEV][ddev].

Once the initial configuration is done, simply copy the content of `vendor/la-haute-societe/craft-elasticsearch/resources/ddev` into your `.ddev` project folder 
then start or restart your DDEV environment.

This will start an Elasticsearch and Kibana services, respectively accessible at <http://projectname.ddev.site:9200> and <http://projectname.ddev.site:5601>.

From the Elasticsearch plugin setting, you use <http://elasticsearch:9200> to point to the Elasticsearch instance.


## Development using a Docker container

To have a development environment up & running in minutes, use Docker:

```sh
cd vendor/la-haute-societe/craft-elasticsearch/resources/docker
docker-compose up
```

It will take around one minute for the containers to be ready (and some more time on first run as Docker will need to 
fetch images).

Once this is done, you can access your Elasticsearch local instance at <http://localhost:9200> and 
Kibana at <http://localhost:5601>.

> **Note**:
> If you want to be able to index your contents with this plugin from your docker php container, don't forget to add the relevant `extra_hosts` 
to your docker-compose php container definition so it points your apache host to your localhost public IP.
For example, if your Craft CMS instance is accessible through `http://docker.test`, you will define an extra_hosts entry as follow: `- "docker.test:xxx.xxx.xxx.xxx"` where xxx.xxx.xxx.xxx represent your public IP.


## Production

If you need a hassle-free, secure, scalable & production-ready solution, [Elastic Cloud][elasticsearch-cloud] is the way
to go.

[elasticsearch-cloud]: https://www.elastic.co/cloud/elasticsearch-service
[ddev]: https://ddev.readthedocs.io/en/stable/
