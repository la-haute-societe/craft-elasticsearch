# Elasticsearch plugin for Craft CMS 3.x Changelog

## Unreleased


## 2.1.1 - 2022-08-24
### Changed
- Change plugin icon
- Update Readme


## 2.1.0 - 2022-08-24
### Added
- Support for ukrainian language (thanks [@sfsmfc][], closes [#27][])
### Fixed
- Fix polish analyzer (thanks [@sfsmfc][], closes [#26][])
- `isIndexInSync` will always result in a cache miss if index is not in sync 
  (thanks [@aloco][], closes [#22][])


## 2.0.0 - 2022-08-23
### Added
- [BREAKING]: Make the plugin compatible with Craft 4 (closes [#23][])


## 1.5.1 – 2022-08-23
### Fixed
- Fix an incorrect default value for the `highlight.pre_tags` & 
  `highlight.post_tags` settings, that could lead to an exception when running a
  search
- Fix a overridden setting warning shown even when the `blacklistedEntryTypes` 
  setting isn't overriden


## 1.5.0 – 2021-12-14
### Added
- Ability to index digital products (thanks [@aloco](https://github.com/aloco))
### Fixed
- Fix index analyzer detection for sites having a "complex" locale (ie. language
  \+ country code)
- Fix Elasticsearch 6 compatibility (fixes [#17][])
- Fix a timezone-related bug affecting the `postDate` & `expiryDate` fields
  ([#16][])


## 1.4.0 - 2020-10-02
### Added
- Ability to index assets
- Console commands to reindex only entries, assets or products
### Changes
- Code refactoring
### Removed
- `SiteController`: it was originally used as a part of the reindexation process
  when called from the Craft CLI, but this was changed in 1.2.0 and it was
  useless since then.


## 1.3.0 - 2020-09-29
### Added
- Compatibility with Elasticsearch 7
- Ability to prefix the Elasticsearch indices names (thanks [@vviacheslavv](https://github.com/vviacheslavv))
- Compatibility with project-config (Fixes #7)
### Changes
- Use looser version constraints in composer.json (possibly fixes #11)
### Fixes
- Test for Yii2 debug module before adding the Elasticsearch panel to it (fixes #12)


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
- All enabled entries and products are indexed now regardless of their live status.
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


[#16]: https://github.com/la-haute-societe/craft-elasticsearch/issues/16
[#17]: https://github.com/la-haute-societe/craft-elasticsearch/issues/17
[#22]: https://github.com/la-haute-societe/craft-elasticsearch/issues/22
[#23]: https://github.com/la-haute-societe/craft-elasticsearch/issues/23
[#26]: https://github.com/la-haute-societe/craft-elasticsearch/issues/26
[#27]: https://github.com/la-haute-societe/craft-elasticsearch/issues/27
[@sfsmfc]: https://github.com/sfsmfc
[@aloco]: https://github.com/aloco
