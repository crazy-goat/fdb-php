# Directory Layer

**Namespace:** `CrazyGoat\FoundationDB\Directory`

## Overview

The directory layer provides a hierarchical namespace for organizing data. Each directory gets a unique short binary prefix allocated by the HighContentionAllocator, avoiding key collisions between different parts of your application. Binary-compatible with official FDB directory layers.

## Creating and Opening Directories

```php
use CrazyGoat\FoundationDB\Directory\DirectoryLayer;

$dir = new DirectoryLayer();

// Create or open (idempotent)
$users = $dir->createOrOpen($db, ['app', 'users']);

// Create only (throws if exists)
$orders = $dir->create($db, ['app', 'orders']);

// Open only (throws if doesn't exist)
$existing = $dir->open($db, ['app', 'users']);
```

### Creating a Directory with an Explicit Prefix

For migration scenarios (restoring a directory from a backup, importing a
partition from another cluster, or pre-allocating a binary prefix that an
external system already references), `create()` accepts an explicit
`prefix` argument that overrides the HighContentionAllocator:

```php
$partition = $dir->create(
    $db,
    ['imported', 'partition'],
    layer: 'partition',
    prefix: "\xFErestored-prefix-bytes",
);
```

The explicit prefix is the **raw byte string** used as the directory's
internal prefix — it is NOT prefixed automatically with the content
subspace key. The layer rejects a caller-supplied prefix that would
collide with anything already in the keyspace:

| Condition                                                  | Result                                       |
|------------------------------------------------------------|----------------------------------------------|
| prefix is `''` (empty)                                     | `DirectoryException`: must not be empty      |
| node-metadata range at the prefix is not empty             | `DirectoryException`: conflicts with metadata |
| content-key range at the prefix is not empty               | `DirectoryException`: overlaps content keys  |
| prefix is non-empty and both ranges are empty              | accepted; directory created                  |

All three rejections **abort the transaction before any write**, so a
failed `create()` does not leave partial state and the same `path` can be
retried with `prefix: null` for auto-allocation.

## Using Directories as Subspaces

DirectorySubspace extends Subspace:

```php
$users = $dir->createOrOpen($db, ['app', 'users']);

// Pack keys within the directory
$db->set($users->pack([42, 'name']), 'Alice');
$db->set($users->pack([42, 'email']), 'alice@example.com');

// Unpack keys
$tuple = $users->unpack($key); // [42, 'name']

// Range queries
[$begin, $end] = $users->range([42]);
```

## Listing Directories

```php
$subdirs = $dir->list($db, ['app']); // ['users', 'orders']
$all = $dir->list($db); // top-level directories
```

## Checking Existence

```php
$exists = $dir->exists($db, ['app', 'users']); // true/false
```

## Moving Directories

```php
$dir->move($db, ['app', 'users'], ['app', 'customers']);
// Data is NOT moved — only the directory mapping changes (O(1) operation)
```

## Removing Directories

```php
$dir->remove($db, ['app', 'orders']); // throws if doesn't exist
$dir->removeIfExists($db, ['app', 'orders']); // returns false if doesn't exist
```

## Directory Layers

Optional string tag for type safety:

```php
$users = $dir->createOrOpen($db, ['app', 'users'], layer: 'user_data');
// Opening with wrong layer throws DirectoryException
$dir->open($db, ['app', 'users'], layer: 'wrong_layer'); // throws!
```

## Subdirectory Operations

DirectorySubspace has directory methods:

```php
$app = $dir->createOrOpen($db, ['app']);

// Create subdirectories relative to this directory
$users = $app->createOrOpen($db, ['users']);
$orders = $app->create($db, ['orders']);

// List subdirectories
$subs = $app->listSubdirectories($db);

// Move within directory
$app->move($db, ['users'], ['customers']);

// Move to absolute path
$users->moveTo($db, ['archive', 'users']);
```

## Directory Partitions

Isolated sub-trees:

```php
// Create a partition (uses layer: 'partition')
$partition = $dir->create($db, ['isolated'], layer: 'partition');

// Partitions have their own DirectoryLayer
// Cannot be used as subspaces (pack/unpack throw LogicException)
// Subdirectories within a partition are fully isolated
$sub = $partition->createOrOpen($db, ['data']);
```

## Custom Node/Content Subspaces

```php
$dir = new DirectoryLayer(
    nodeSubspace: new Subspace(rawPrefix: "\xFE"),    // default
    contentSubspace: new Subspace(),                   // default
);
```

## Error Handling

DirectoryException for directory-specific errors.

## Transactional Safety

All directory operations accept `Transactor` ($db or $tr), so they participate in transactions.
