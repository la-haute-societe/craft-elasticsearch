# TODO

## IndexableXXXQuery

Create IndexableXXXQuery classes (based on Craft ElementQueries) to get rid of
the following methods:
  - `getIndexableElementModels()`
  - `getIndexableEntryModels()`
  - `getIndexableAssetModels()`
  - `getIndexableProductModels()`
  - `getIndexableElementsQuery()`
  - `getIndexableEntriesQuery()`
  - `getIndexableAssetQuery()`
  - `getIndexableProductQuery()`

They should have:
  - a static `find()` method that return a new instance of the XXXQuery class
    with prepopulated parameters (blacklisted sections, status, non-empty URL…)
  - `one()` & `all()` instance methods to get `IndexableElementModel` instances


## Add the following CP settings

  - `isEntryIndexingEnabled`: defaults to `true`, allows to disable the indexing
    of entries
  - `isAssetsIndexingEnabled`: defaults to `true`, allows to disable the
    indexing of assets
  - `isProductsIndexingEnabled`: defaults to `true`, allows to disable the
    indexing of products
  - `isDigitalProductsIndexingEnabled`: defaults to `true`, allows disabling the
    indexation of digital products
  - `blacklistedProductTypes`: defaults to `[]`, allows disabling indexation of
    the product types
  - `blacklistedDigitalProductTypes`: defaults to `[]`, allows disabling
    indexation of the given digital product types


## Refactor the following settings

  - `blacklistedEntryTypes`: must include the handle of the section
    (e.g. `section:entryType`) as entry type handles are only unique in a given
    section
  - `contentExtractorCallback`: Use an event instead
  - `elementContentCallback`: Use an event instead
  - `resultFormatterCallback`: Use an event instead
  - `extraFields`: Use an event instead
