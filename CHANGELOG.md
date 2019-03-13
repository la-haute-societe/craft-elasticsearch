# Elasticsearch plugin for Craft CMS 3.x Changelog

## 1.1.0 - Unreleased
### Added
- Craft Commerce product support (Enh #1)
- Ability to index and search additional data (Enh #2)
- `rawResult` in result fields to get a reference to the raw ElasticsearchRecord result object
- `elementHandle` in result fields to be able to get the element type related to the result
- `resultFormatterCallback` configuration callback in order to make changes to the results data
- `elementContentCallback` configuration callback to be able to implement custom method to get the element content to index
- `EVENT_BEFORE_CREATE_INDEX`, `EVENT_BEFORE_SAVE` and `EVENT_BEFORE_SEARCH` events of `ElasticsearchRecord` can be listened to customized various aspects of the Elastisearch life cycle 

### Changed
- IndexEntryException has been replaced by IndexElementException
- Guzzle 6 is now used to get page content of elements instead of the Twig template renderer
- Merge highlight results for all fields
- Removed default highlight for title field

### Fixed
- Prefixed table names configuration where leading to a 'Column not found Error' (Bug #3)

## 1.0.0 - 2018-12-12
### Added
- Initial release
