# Watches

## Overview

Watches let you get notified when a key's value changes. A watch returns a Future that resolves when the watched key is modified by another transaction.

## Basic Watch

```php
use CrazyGoat\FoundationDB\Transaction;

$db->transact(function (Transaction $tr) {
    $watch = $tr->watch('config:version');
    $tr->set('config:version', '2');
    // $watch fires after commit when value changes again
});
```

## Database-Level Watch Methods

The Database class provides convenience methods for watching keys:

```php
// Watch a key (auto-transact)
$watch = $db->watch('config:version');
// $watch is FutureVoid — call $watch->await() to block until change

// Get current value AND set up watch
[$value, $watch] = $db->getAndWatch('config:version');
echo "Current: {$value}\n";
// $watch fires when value changes

// Set value AND watch for changes
$watch = $db->setAndWatch('config:version', '3');

// Clear value AND watch
$watch = $db->clearAndWatch('config:version');
```

## Waiting for Changes

```php
[$value, $watch] = $db->getAndWatch('config:version');
echo "Current value: {$value}\n";

// Block until the key changes
$watch->await();
echo "Value changed!\n";

// Read the new value
$newValue = $db->get('config:version');
```

## Watch Lifecycle

- Watch is created within a transaction
- Watch becomes active after the transaction commits
- Watch fires when the key is modified by another transaction
- `$watch->isReady()` — check without blocking
- `$watch->cancel()` — cancel the watch

## Important Notes

- Watches are **NOT persistent** — they exist only in the client process
- A watch may fire spuriously (check the value after waking)
- Watches survive transaction retries within `transact()`
- Don't use watches for high-frequency polling — they're designed for infrequent changes
