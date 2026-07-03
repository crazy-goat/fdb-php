# Changelog

## [Unreleased]

### Fixed
- [#51] RangeResult: `limit: 0` now correctly returns an empty result instead of one full batch.
  Previously, passing `limit: 0` to `RangeOptions` was equivalent to "unlimited" for the first
  batch and then stopped immediately, producing an ambiguous single-batch result. `limit: 0` is
  now explicitly treated as "no rows" (empty iterator), while `limit: null` remains the way to
  request all matching rows.
