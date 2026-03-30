# Tenants

## Overview

Tenants provide data isolation within a single FoundationDB cluster. Each tenant has its own key space — keys in different tenants never collide.

## Creating Tenants (via AdminClient)

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;

FDB::apiVersion(730);
$db = FDB::open();

// Create a tenant
$db->admin()->createTenant('tenant_a');
$db->admin()->createTenant('tenant_b');
```

## Using Tenants

```php
$tenant = $db->openTenant('tenant_a');

// Create a transaction scoped to this tenant
$tr = $tenant->createTransaction();
$tr->set('key', 'value'); // This key is isolated to tenant_a
$tr->commit()->await();

// Read from tenant
$tr2 = $tenant->createTransaction();
$value = $tr2->get('key')->await(); // 'value'
```

## Tenant ID

```php
$tenant = $db->openTenant('tenant_a');
$id = $tenant->getId(); // unique integer ID
```

## Managing Tenants (via AdminClient)

```php
$admin = $db->admin();

// List all tenants
$tenants = $admin->listTenants(); // ['tenant_a', 'tenant_b']

// Delete a tenant
$admin->deleteTenant('tenant_b');
```

## Important Notes

- Tenant names are strings
- Each tenant has a completely isolated key space
- Tenant management requires the AdminClient
- Tenants are created via FoundationDB Special Keys
- The Tenant object is destroyed (and the FDB tenant handle released) when garbage collected
