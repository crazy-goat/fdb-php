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

```php
// Configure redundancy and storage engine
$admin->configure('double ssd');

// Single token is also accepted — storage defaults to "ssd" matching FDB.
$admin->configure('single');
```

`configure()` splits its argument on whitespace and accepts one
(`<redundancy>`) or two (`<redundancy> <storage>`) tokens. Each token must
match `[A-Za-z0-9_-]{1,64}` — control bytes, high bytes, dots, colons, and
surrounding whitespace are rejected so a malformed string cannot be silently
parsed by FDB. Malformed input raises `\InvalidArgumentException` before the
transaction begins.

The configured values are written to two Special Keys:

| Special Key                          | Value (`configure('double ssd')`) |
|--------------------------------------|-----------------------------------|
| `\xff\xff/configuration/redundancy`  | `double`                          |
| `\xff\xff/configuration/storage`     | `ssd`                             |

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

**WARNING: May cause data loss!**

```php
// Emergency operation
$admin->forceRecovery('dc_id');
```

The `dcId` argument is validated against `[A-Za-z0-9_-]{1,64}` and rejected
if empty. (Note: dots and colons are explicitly forbidden here even though
they are permitted in tenant names and server addresses, because FoundationDB
does not accept them in the `\xff\xff/management/force_recovery` key path.)

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
| `configure`       | 1 or 2 whitespace-split tokens   | `[A-Za-z0-9_-]` per token        | 64 bytes   | `\InvalidArgumentException`               |
| `forceRecovery`   | dcId                             | `[A-Za-z0-9_-]`                  | 64 bytes   | `\InvalidArgumentException`               |

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
| `configure` | `string $configuration` | `void` | Configure cluster |
| `excludeServer` | `string $address` | `void` | Exclude server |
| `includeServer` | `string $address` | `void` | Include server |
| `consistencyCheck` | — | `bool` | Check consistency |
| `getClusterStatus` | — | `array<string, mixed>` | Get cluster status |
| `forceRecovery` | `string $dcId` | `void` | Force recovery |
