# Elasticsearch plugin for Craft CMS 3.x Changelog

## 1.2.2 - 2019-06-11

### Fixed
- Fix a bug where disabled elements where indexed anyway

## 1.2.1 - 2019-06-10

### Fixed
- Entries indexing was broken in Craft CMS 3.2 due to a last minute change

## 1.2.0 - 2019-06-04

> {warning} This update introduce a way to honor post and expiry dates for entries and products.
The default search query has been updated in order to filter indexed elements based on those fields.
If you used `ElasticsearchRecord::EVENT_BEFORE_SEARCH` event to alter the search query, please be sure to update to reflect these changes.
See README for more infos.
After plugin update, Elastisearch indexes will be rebuilt in order to take these changes in consideration.

### Added
- Craft CMS 3.2 compatibility
- Instructions to setup a DDEV environment

### Changed
- `postDate` and `expiryDate` are now available in search results
- Default search query has been updated in order to filter live elements
- All enabled entries and products are indexed now regardless of there live status. 
- Console command `elasticsearch/elasticsearch/reindex-all` do not need additional parameter in order to run.

### Fixed
- The search method now honor `postDate` and `expiryDate` to only show live elements

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
