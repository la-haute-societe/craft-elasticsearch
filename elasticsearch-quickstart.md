# Elasticsearch quickstart

The craft-elasticsearch plugin is useless if you don't have an Elasticsearch server to connect to.

In this guide we'll walk you through the steps to set up both a development and a production Elasticsearch instance.



## Development

To have a development environment up & running in minutes, use Docker:

```sh
cd <plugin_dir>/resources/docker
docker-compose up
```

It will take around one minute for the containers to be ready (and some more time on first run as Docker will need to 
fetch images).

Once this is done, you can access your Elasticsearch local instance at <http://localhost:9200> and 
Kibana at <http://localhost:5601>.



## Production

If you need a hassle-free, secure, scalable & production-ready solution, [Elastic Cloud](elasticsearch-cloud) is the way
to go.

[elasticsearch-cloud]: https://www.elastic.co/cloud/elasticsearch-service
