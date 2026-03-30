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

## Cluster Configuration

```php
// Configure redundancy and storage engine
$admin->configure('double ssd');
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
