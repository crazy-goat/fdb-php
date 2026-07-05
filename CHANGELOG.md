# Changelog

## [Unreleased]

### Fixed
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
- [#40] `DirectoryLayer::move()` now enforces explicit bounds on its path
  arguments at the PHP trust boundary, replacing the previous silent-
  permissive behavior that allowed moving a directory into its own subtree
  or across a partition boundary. Five explicit checks were added (and unit
  + integration tests cover all of them):

  | Rule tested on the supplied paths                                                                                          | Behaviour                                                                                                |
  |---------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
  | `newPath` is empty                                                                                                        | `DirectoryException`: `Path must not be empty.` (existing path guard retained)                           |
  | `oldPath` is empty                                                                                                        | `DirectoryException`: `Path must not be empty.` (existing path guard retained)                           |
  | `newPath` equals `oldPath`                                                                                                | `DirectoryException`: source and destination paths are identical (avoids a no-op that still rewrites the subdirs index) |
  | `newPath` begins with `oldPath` as a prefix (`array_slice($newPath, 0, count($oldPath)) === $oldPath`)                   | `DirectoryException`: destination is inside the source's subtree (would create a cycle / unreachable sub-tree) |
  | `count($oldPath) > 64` or `count($newPath) > 64` (`MAX_MOVE_PATH_DEPTH`)                                                  | `DirectoryException`: path exceeds maximum depth (bounds defence against malformed inputs producing arbitrarily deep entries) |
  | the immediate parents of `oldPath` and `newPath` carry different partition layers                                                              | `DirectoryException`: `partition crossings are disallowed` (a directory must not be silently re-parented out of or into a different partition; the check is on the parents, not on `oldPath`'s own `layer` attribute, because a child borrows its parent's partition membership regardless of its own layer string) |

  Previously, `move(['a'], ['a','b'])` succeeded silently and produced a
  cycle in the subdirs index; `move(['p','a'], ['x','a'])` silently
  re-parented a partition node under a top-level parent, leaving the
  moved prefix in a different layer's content space. The fix routes
  every call through `DirectoryLayer::validateMoveBounds()` (path-only
  checks) and `DirectoryLayer::assertSamePartitionLayer()`
  (layer-aware check), both of which throw `DirectoryException` with a
  printable rendering of the offending paths (control bytes, DEL, high
  bytes, and `/` inside segments are all escaped to `\xHH`) **before any
  `set()` / `clear()` runs**, so a rejected move leaves the directory
  index untouched and the same `oldPath` can be retried with a
  different `newPath`. Path-only validation is covered by 16 cases in
  `tests/Unit/DirectoryMoveBoundsValidationTest.php`; layer-aware
  partition-crossing validation is covered by 8 cases in the same unit
  test; transactional happy and rejection paths (sibling rename,
  re-parenting under an existing parent, same-partition rename, and
  self-subtree rejection leaving the index untouched) are covered in
  `tests/Integration/DirectoryTest.php`. The new contract is
  documented in the class-level doc-block on
  `DirectoryLayer::move()` and in `docs/directory-layer.md`.
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
- [#73] `RangeResult` reverse pagination now yields each key exactly once.
  Previously the iterator advanced the upper-bound selector using
  `KeySelector::firstGreaterOrEqual($lastKey)` at every batch boundary, an
  *inclusive* selector, so the boundary key already yielded by the previous
  batch was yielded again at the head of the next batch. For ranges spanning
  more than one server page the output therefore contained duplicates. The
  reverse branch now mirrors the forward branch and uses
  `KeySelector::firstGreaterThan($lastKey)`, so the key just produced is
  excluded from the next batch. Integration tests in
  `tests/Integration/RangeReadTest.php` cover backward iteration both with
  and without an explicit `limit` over ranges large enough to force multiple
  server pages.
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
