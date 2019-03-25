# Elasticsearch plugin for Craft CMS 3.x Changelog

## 1.1.0 - 2019-03-25

> {warning} The way page content are indexed have changed and now rely on a Guzzle client implementation. 
If your entries are not indexed anymore after upgrade, please check you network configuration (specially when using docker containers) and the documentation for `elementContentCallback` new configuration parameter to override the Guzzle implementation if needed. 

### Added
- Craft Commerce product support (#1)
- Ability to index and search additional data (#2)
- `rawResult` in result fields to get a reference to the raw ElasticsearchRecord result object
- `elementHandle` in result fields to be able to get the element type related to the result
- `resultFormatterCallback` configuration callback in order to make changes to the results data
- `elementContentCallback` configuration callback to be able to implement custom method to get the element content to index
- `EVENT_BEFORE_CREATE_INDEX`, `EVENT_BEFORE_SAVE` and `EVENT_BEFORE_SEARCH` events of `ElasticsearchRecord` can be listened to customized various aspects of the Elastisearch life cycle 

### Changed
- Guzzle 6 is now used to get page content of elements instead of the Twig template renderer
- Updated documentation
- `IndexEntryException` class has been replaced by `IndexElementException`
- Merge highlight results for all fields
- Removed default highlight for title field

### Fixed
- Prefixed table names configuration where leading to a 'Column not found Error' (#3)

## 1.0.0 - 2018-12-12
### Added
- Initial release
