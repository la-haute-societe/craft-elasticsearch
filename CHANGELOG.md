# Elasticsearch plugin for Craft CMS 3.x Changelog

## 1.1.0 - Unreleased
### Added
- Craft Commerce product support (Enh #1)
- Ability to index and search additional data (Enh #2)

### Changed
- IndexEntryException has been replaced by IndexElementException
- Guzzle 6 is now used to get page content of elements instead of the Twig template renderer

### Fixed
- Prefixed table names configuration where leading to a 'Column not found Error' (Bug #3)

## 1.0.0 - 2018-12-12
### Added
- Initial release
