# AdminClient Documentation

**Namespace:** `CrazyGoat\FoundationDB`

## Overview

The AdminClient provides cluster administration operations using FoundationDB Special Keys. Access via `$db->admin()`.

## Getting the AdminClient

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;

FDB::apiVersion(730);
$db = FDB::open();
$admin = $db->admin();
```

## Tenant Management

```php
$admin->createTenant('my_tenant');
$admin->deleteTenant('my_tenant');
$tenants = $admin->listTenants(); // list<string>
```

Tenant names are validated against the allow-list `[A-Za-z0-9._-]{1,256}`,
must start with an alphanumeric character, and may not contain control bytes,
high bytes, whitespace, or `/` (a slash would silently address a different
management sub-key under `\xff\xff/management/tenant/map/`). Inputs that
violate the contract raise `\InvalidArgumentException` synchronously at the
call site, before the transaction is opened.

## Cluster Configuration

**Not supported via AdminClient.** `configure()` is deprecated and throws
`\LogicException` synchronously (after validating its argument). FoundationDB
does not expose cluster-wide configuration (redundancy mode, storage engine)
through the special-key space: the documented `\xff\xff/configuration/`
module only covers process class types
(`\xff\xff/configuration/process/class_type/<address>`) and coordinators
(`\xff\xff/configuration/coordinators/*`), and writes to any other key in
that prefix fail at commit with `special_keys_no_module_found`. Cluster
configuration must be performed with the `fdbcli` `configure` command
instead (e.g. `fdbcli -C fdb.cluster --exec 'configure double ssd'`).

```php
// Still validates the token shape ([A-Za-z0-9_-]{1,64}, 1-2 tokens) before
// throwing, so a malformed string keeps failing with a precise
// \InvalidArgumentException.
try {
    $admin->configure('double ssd');
} catch (\LogicException $e) {
    // use fdbcli `configure` instead
}
```

## Server Management

```php
// Exclude a server from the cluster
$admin->excludeServer('127.0.0.1:4500');

// Include it back
$admin->includeServer('127.0.0.1:4500');

// Reboot a worker process
$admin->rebootWorker('127.0.0.1:4500');
$admin->rebootWorker('127.0.0.1:4500', checkFile: true, suspendDuration: 10);
```

Server addresses are validated against `[A-Za-z0-9._:-]{1,256}` and rejected
if empty. In particular, names containing `/`, whitespace, control bytes or
high bytes are refused — without validation, an address like `127.0.0.1/24`
would have written `\xff\xff/management/excluded/127.0.0.1/24`, addressing
an entirely different Special Key than intended.

## Cluster Status

```php
// Get full cluster status as JSON array
$status = $admin->getClusterStatus(); // array<string, mixed>

// Check consistency
$isConsistent = $admin->consistencyCheck(); // bool
```

## Force Recovery

Forced recovery is available via the direct C API entry point
`fdb_database_force_recovery_with_data_loss()` (bound in #97) — the same
RPC the cluster controller and the `fdbcli`
`force_recovery_with_data_loss <dcid>` command use. This replaces the old
special-key approach, which never worked (a write to
`\xff\xff/management/force_recovery` fails at commit with
`special_keys_no_module_found`).

```php
// WARNING: may cause data loss! Use only in emergency situations when the
// primary datacenter is gone.
$admin->forceRecoveryWithDataLoss('dc_id');

// forceRecovery() is an alias and behaves identically.
$admin->forceRecovery('dc_id');
```

The dcId is validated against `[A-Za-z0-9_-]{1,64}` and rejected with
`\InvalidArgumentException` before the FFI call.

## Disaster-Recovery Snapshots

`createSnapshot()` binds `fdb_database_create_snapshot()` — the entry point
behind the `fdbcli` `snapshot` command. The cluster must have snapshot
support configured, otherwise the future resolves to an error.

```php
$admin->createSnapshot(
    uid: '0123456789abcdef0123456789abcdef', // 32 hex characters
    snapCommand: 'start',
);
```

The UID must be exactly 32 hexadecimal characters (`[0-9a-fA-F]{32}`) and
the snapshot command printable ASCII (0x20–0x7E, 1–256 bytes); both are
validated before the C call.

## Validation contract summary

Every public method that takes a caller-supplied identifier validates it
before opening a transaction. The full contract is:

| Method            | Validated input                  | Allow-list                       | Max length | Failure path                              |
|-------------------|----------------------------------|----------------------------------|------------|-------------------------------------------|
| `createTenant`    | tenant name                      | `[A-Za-z0-9._-]` (start alnum)   | 256 bytes  | `\InvalidArgumentException`               |
| `deleteTenant`    | tenant name                      | (same as `createTenant`)         | 256 bytes  | `\InvalidArgumentException`               |
| `excludeServer`   | server address (host:port)       | `[A-Za-z0-9._:-]`                | 256 bytes  | `\InvalidArgumentException`               |
| `includeServer`   | server address (host:port)       | `[A-Za-z0-9._:-]`                | 256 bytes  | `\InvalidArgumentException`               |
| `rebootWorker`    | server address                   | `[A-Za-z0-9._:-]`                | 256 bytes  | `\InvalidArgumentException`               |
| `configure`       | 1 or 2 whitespace-split tokens   | `[A-Za-z0-9_-]` per token        | 64 bytes   | `\InvalidArgumentException`, then `\LogicException` (unsupported operation) |
| `forceRecovery` / `forceRecoveryWithDataLoss` | dcId              | `[A-Za-z0-9_-]`                  | 64 bytes   | `\InvalidArgumentException`               |
| `createSnapshot`  | snapshot UID                      | `[0-9a-fA-F]`, exactly 32 chars  | 32 bytes   | `\InvalidArgumentException`               |
| `createSnapshot`  | snapshot command                  | printable ASCII (0x20–0x7E)      | 256 bytes  | `\InvalidArgumentException`               |

Byte-level safety is shared with `KeyValueLimits`: every Special Key path
spliced together from caller input is also checked against the FDB key size
limit (10,000 bytes) and the FFI 32-bit length boundary.

## All Methods Reference

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `createTenant` | `string $name` | `void` | Create a new tenant |
| `deleteTenant` | `string $name` | `void` | Delete a tenant |
| `listTenants` | — | `list<string>` | List all tenants |
| `rebootWorker` | `string $address, bool $checkFile = false, int $suspendDuration = 0` | `void` | Reboot worker |
| `configure` | `string $configuration` | `void` | Deprecated — throws `\LogicException` (unsupported by special keys) || `excludeServer` | `string $address` | `void` | Exclude server |
| `includeServer` | `string $address` | `void` | Include server |
| `consistencyCheck` | — | `bool` | Check consistency |
| `getClusterStatus` | — | `array<string, mixed>` | Get cluster status |
| `forceRecovery` | `string $dcId` | `void` | Force recovery into a datacenter via the C API — may cause data loss |
| `forceRecoveryWithDataLoss` | `string $dcId` | `void` | Same as `forceRecovery` (explicit name) — may cause data loss |
| `createSnapshot` | `string $uid, string $snapCommand` | `void` | Start a DR snapshot (`fdbcli snapshot` equivalent) |
