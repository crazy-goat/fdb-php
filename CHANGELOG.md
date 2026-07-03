# Changelog

## [Unreleased]

### Fixed
- [#56] PHPStan: configure `--memory-limit=512M` in the `phpstan` composer script to prevent
  analysis from crashing under the default 128M limit on large codebases.
- [#36] FutureStringArray: `await()` now copies `char*` elements into owned PHP strings via
  `FFI::string()` before releasing the future's memory. Previously, the method returned raw
  `FFI\CData` pointers that became dangling after `releaseMemory()`, causing use-after-free in
  `getAddressesForKey()` and any other consumer.
- [#51] RangeResult: `limit: 0` now correctly returns an empty result instead of one full batch.
  Previously, passing `limit: 0` to `RangeOptions` was equivalent to "unlimited" for the first
  batch and then stopped immediately, producing an ambiguous single-batch result. `limit: 0` is
  now explicitly treated as "no rows" (empty iterator), while `limit: null` remains the way to
  request all matching rows.
