# Changelog

## [Unreleased]

### Fixed
- [#43] `AdminClient` now validates every caller-supplied input against a
  fixed-byte allow-list at the PHP trust boundary, replacing the previous
  behaviour of splicing raw caller input directly into privileged Special
  Keys (`\xff\xff/management/tenant/map/<name>`,
  `\xff\xff/management/excluded/<addr>`,
  `\xff\xff/management/force_recovery`, `\xff\xff/configuration/redundancy`,
  `\xff\xff/configuration/storage`). Six admin methods are gated
  (`createTenant`, `deleteTenant`, `excludeServer`, `includeServer`,
  `rebootWorker`, `configure`, `forceRecovery`):

  | Method            | Validated input                  | Allow-list                       | Max length | On violation                                |
  |-------------------|----------------------------------|----------------------------------|------------|---------------------------------------------|
  | `createTenant`    | tenant name                      | `[A-Za-z0-9._-]`, start alnum    | 256 bytes  | `\InvalidArgumentException` (sync)          |
  | `deleteTenant`    | tenant name                      | `[A-Za-z0-9._-]`, start alnum    | 256 bytes  | `\InvalidArgumentException` (sync)          |
  | `excludeServer`   | server address (host:port)       | `[A-Za-z0-9._:-]`                | 256 bytes  | `\InvalidArgumentException` (sync)          |
  | `includeServer`   | server address (host:port)       | `[A-Za-z0-9._:-]`                | 256 bytes  | `\InvalidArgumentException` (sync)          |
  | `rebootWorker`    | server address                   | `[A-Za-z0-9._:-]`                | 256 bytes  | `\InvalidArgumentException` (sync)          |
  | `configure`       | 1 or 2 whitespace-split tokens   | `[A-Za-z0-9_-]` per token        | 64 bytes   | `\InvalidArgumentException` (sync)          |
  | `forceRecovery`   | dcId                             | `[A-Za-z0-9_-]`                  | 64 bytes   | `\InvalidArgumentException` (sync)          |

  Previously, a tenant name containing `/` would silently write into
  `\xff\xff/management/tenant/map/foo/bar` instead of the intended tenant
  key, a server address like `127.0.0.1/24` would silently produce the
  Special Key `\xff\xff/management/excluded/127.0.0.1/24`, and
  `configure()` accepted any whitespace-split string with no token
  validation. The fix routes every caller-supplied identifier through
  private static helpers (`AdminClient::validateTenantName`,
  `validateAddress`, `validateToken`, `parseConfiguration`) that throw
  `\InvalidArgumentException` with a printable rendering of the offending
  input before any transaction begins — so a malformed input can no longer
  reach FDB, and the failure surfaces at the call site instead of as an
  opaque commit-time error. Bounds checks are documented in the
  `AdminClient` class-level doc-block and in `docs/admin.md`; the
  pure-validation logic is covered by 47 cases in
  `tests/Unit/AdminClientInputValidationTest.php`; transactional happy and
  rejection paths are covered by 27 cases in
  `tests/Integration/AdminInputValidationTest.php`.
- [#41] `DirectoryLayer::create()` now validates a caller-supplied
  `prefix` argument before writing anything. Three explicit checks were
  added (and unit + integration tests cover all of them):

  | Condition tested on the proposed prefix                      | Behaviour                                                         |
  |--------------------------------------------------------------|-------------------------------------------------------------------|
  | prefix is `''` (empty)                                       | `DirectoryException`: must not be empty.                          |
  | node-metadata range under the proposed prefix is not empty   | `DirectoryException`: conflicts with existing directory metadata. |
  | content-key range under the proposed prefix is not empty     | `DirectoryException`: overlaps existing content keys.             |
  | none of the above                                            | accepted; directory created                                       |

  Previously, `create(..., $prefix)` only ran the conflict check inside
  the `if ($prefix === null)` allocation branch; a caller-supplied
  prefix was written directly into the subdirs index without any check,
  so a manual prefix overlapping existing metadata or content keys
  silently corrupted or overwrote data. The fix routes caller-supplied
  prefixes through `DirectoryLayer::validateRawPrefix()` (a static,
  self-contained validation helper), which throws with a
  `DirectoryException` carrying a printable rendering of the offending
  key. The check runs inside the transaction before any `set()` call,
  so a failed create leaves no partial state. Strict boundaries
  (empty prefix check, both free-range probes, printable diagnostic
  message) are covered by `tests/Unit/DirectoryPrefixValidationTest.php`;
  end-to-end acceptance and round-trip behavior are covered in
  `tests/Integration/DirectoryTest.php`.
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
- [#39] `Tuple::unpack()` and the encode side now reject
  deeply-nested inputs at a fixed, public bound
  (`Tuple::MAX_NESTING_DEPTH`, currently `100`) instead of
  recursing without limit. Previously, a stored value made up
  entirely of `\x05` TYPE_NESTED bytes (the issue's PoC was
  hundreds of kilobytes) would exhaust the call stack and abort
  the process whenever an application called `unpack()` /
  `Subspace::unpack()` on data produced by a less-trusted writer.
  The bound is enforced symmetrically on
  `Tuple::pack()`, `Tuple::unpack()`,
  `Tuple::packWithVersionstamp()`,
  `Tuple::hasIncompleteVersionstamp()`, and on the encode-side
  helpers `findVersionstampOffset` and `countVersionstamps`. Any
  payload whose deepest recursion step would exceed
  `MAX_NESTING_DEPTH` raises
  `\InvalidArgumentException` immediately at the offending call;
  wire bytes that round-trip just at the limit are still accepted,
  so legitimate deeply-nested user data is unaffected.
