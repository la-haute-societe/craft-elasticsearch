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
