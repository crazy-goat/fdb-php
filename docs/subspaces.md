# Subspaces

**Namespace:** `CrazyGoat\FoundationDB`

## Overview

Subspaces provide key prefix management using the tuple layer. They let you organize keys into logical groups.

## Creating Subspaces

```php
use CrazyGoat\FoundationDB\Subspace;

// From a tuple prefix
$users = new Subspace(['users']);

// From a raw prefix
$raw = new Subspace(rawPrefix: "\x01\x02");

// Combined
$combined = new Subspace(['app'], rawPrefix: "\x01");
```

## Packing and Unpacking Keys

```php
$users = new Subspace(['users']);

// Pack a key within the subspace
$key = $users->pack([42, 'name']); // binary key for ('users', 42, 'name')

// Unpack a key — removes the subspace prefix
$tuple = $users->unpack($key); // [42, 'name']
```

## Range Queries with Subspaces

```php
$users = new Subspace(['users']);

// Get range boundaries for all keys in subspace
[$begin, $end] = $users->range(); // all ('users', ...)

// Get range for a specific sub-prefix
[$begin, $end] = $users->range([42]); // all ('users', 42, ...)
```

## Key Membership

```php
$users = new Subspace(['users']);
$key = $users->pack([42]);

$users->contains($key);  // true
$users->contains('other'); // false
```

## Nested Subspaces

```php
$app = new Subspace(['app']);
$users = $app->subspace('users');
$orders = $app->subspace('orders');

$users->pack([42, 'name']); // ('app', 'users', 42, 'name')
```

## Using Subspaces with Transactions

```php
$users = new Subspace(['users']);

$db->transact(function (Transaction $tr) use ($users) {
    $tr->set($users->pack([42, 'name']), 'Alice');
    $tr->set($users->pack([42, 'email']), 'alice@example.com');
    
    // Read all data for user 42
    foreach ($tr->getRangeStartsWith($users->pack([42])) as $kv) {
        $tuple = $users->unpack($kv->key);
        echo "{$tuple[1]} = {$kv->value}\n";
    }
});
```

## Versionstamp Support

```php
use CrazyGoat\FoundationDB\Tuple\Versionstamp;

$logs = new Subspace(['logs']);
$packed = $logs->packWithVersionstamp([Versionstamp::incomplete(0), 'entry']);
// Use with $tr->setVersionstampedKey()
```

## KeyConvertible Interface

Subspace implements KeyConvertible, so you can use it directly as a key:

```php
$users = new Subspace(['users']);
$tr->get($users); // equivalent to $tr->get($users->key())
```

## Properties

- `$subspace->rawPrefix` — the raw binary prefix (readonly)
- `$subspace->key()` — same as rawPrefix
- `$subspace->asFoundationDbKey()` — same as key()
