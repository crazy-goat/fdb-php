# Changelog

## [Unreleased]

### Fixed
- [#48] FoundationDB key/value lengths now checked at the PHP trust boundary
  with explicit, named exceptions instead of an unchecked `strlen()` flowing
  straight into a C `int` FFI parameter. New `KeyValueLimits::assertValidKey()` /
  `assertValidValue()` / `assertValidRangeEndpoint()` / `assertValidFfiLength()`
  enforce:
  - keys may not be empty and may not exceed 10,000 bytes (FDB limit, code 2102);
  - values may not exceed 100,000 bytes (FDB limit, code 2103);
  - any byte string crossing the FFI surface may not exceed 2,147,483,647 bytes
    (the signed 32-bit boundary of libfdb_c's length parameters; the previous
    code would have silently truncated a `> 2 GB` payload before libfdb_c could
    see it).
  Violations throw `\InvalidArgumentException` immediately at the call site
  (`set()`, `clear()`, `clearRange()`, `atomicOp()`, `watch()`, `get()`,
  `getRange()`, `getKey()`, `addReadConflictRange()`, `addWriteConflictRange()`,
  `setOption()`) so the application sees the failure with the offending length
  rather than as an opaque code on `commit()`. The PHP-side guard does not
  change the transaction-size aggregation limit, which `libfdb_c` continues
  to enforce on commit (code 2101).
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
- [#50] `ReadTransaction::getInt()` and `HighContentionAllocator::decodeCount()` now reject
  stored values longer than 8 bytes with a clear `RuntimeException` instead of silently
  truncating to the first 8 bytes via `unpack('P')`. The previous behavior could return
  a wrong integer value if the stored value was malformed or larger than the 8-byte
  little-endian integer contract.
