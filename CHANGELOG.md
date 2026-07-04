# Changelog

## [Unreleased]

### Fixed
- [#37] AdminClient::listTenants: end-key for tenant range scan now uses
  `KeyUtil::strinc(self::TENANT_MAP_PREFIX)` instead of a single-quoted `'\xff'` literal.
  The old code silently returned an incomplete tenant list because single-quoted `'\xff'`
  is the 4-byte ASCII string `\xff` (0x5C 0x78 0x66 0x66), not the byte 0xFF. This caused
  all tenants with names starting at or above byte 0x5C (i.e. essentially all lowercase
  letters) to be omitted from the result.
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
