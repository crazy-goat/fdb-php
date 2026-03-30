<?php

/**
 * Watch API Example
 *
 * This script demonstrates FoundationDB watch operations:
 * - Setting up a watch with getAndWatch()
 * - Checking watch->isReady()
 * - Understanding blocking behavior with await()
 * - Using setAndWatch() and clearAndWatch()
 *
 * Note: Watches are one-shot and fire when the key changes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;

echo "=== FoundationDB Watch API ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

$prefix = 'example/watch/';

// Cleanup
echo "Cleaning up existing test data...\n";
$db->clearRangeStartsWith($prefix);
echo "Done.\n\n";

// Basic watch with getAndWatch
echo "--- Basic Watch with getAndWatch() ---\n";
echo "Setting up a watch on a key...\n";

// Set initial value
$db->set($prefix . 'watched_key', 'initial_value');
echo "Set initial value: 'initial_value'\n";

// Get current value and set up watch
[$value, $watch] = $db->getAndWatch($prefix . 'watched_key');
echo "Current value: '{$value}'\n";
echo "Watch created. The watch will fire when the key changes.\n\n";

// Check if watch is ready (it shouldn't be yet)
echo "Checking watch status:\n";
echo "  isReady() before change: " . ($watch->isReady() ? 'true' : 'false') . "\n";

// Change the value
$db->set($prefix . 'watched_key', 'new_value');
echo "\nChanged value to 'new_value'\n";

// Now the watch should be ready
// Note: There might be a small delay, so we may need to poll briefly
$attempts = 0;
while (!$watch->isReady() && $attempts < 10) {
    usleep(10000); // 10ms
    ++$attempts;
}

echo "  isReady() after change: " . ($watch->isReady() ? 'true' : 'false') . "\n";
echo "  (Checked {$attempts} times with 10ms delays)\n\n";

// Important note about blocking
echo "--- Understanding Blocking Behavior ---\n";
echo "The watch->await() method blocks until the watch fires.\n";
echo "This is useful when you want to wait for a change:\n\n";

// Set up a new watch
$db->set($prefix . 'blocking_key', 'start');
echo "Set up blocking_key with value 'start'\n";

// Create watch in background (simulate)
echo "Setting up watch and simulating async change...\n";

// In a real application, another process or thread would change the key
// Here we'll just demonstrate the concept

[$blockingValue, $blockingWatch] = $db->getAndWatch($prefix . 'blocking_key');
echo "Watch created, current value: '{$blockingValue}'\n";
echo "Note: await() would block here until the key changes\n";
echo "  (Not calling await() in this demo to avoid blocking)\n\n";

// Cancel the watch if we don't need it
$blockingWatch->cancel();
echo "Watch cancelled.\n\n";

// setAndWatch
echo "--- setAndWatch() ---\n";
echo "Atomically sets a value and creates a watch in one operation.\n\n";

$setWatch = $db->setAndWatch($prefix . 'set_watch_key', 'value1');
echo "Set value to 'value1' and created watch\n";
echo "  isReady(): " . ($setWatch->isReady() ? 'true' : 'false') . "\n";

// Change the value to trigger the watch
$db->set($prefix . 'set_watch_key', 'value2');
echo "\nChanged value to 'value2'\n";

$attempts = 0;
while (!$setWatch->isReady() && $attempts < 10) {
    usleep(10000);
    ++$attempts;
}
echo "  isReady() after change: " . ($setWatch->isReady() ? 'true' : 'false') . "\n\n";

// clearAndWatch
echo "--- clearAndWatch() ---\n";
echo "Atomically clears a key and creates a watch.\n\n";

$db->set($prefix . 'clear_watch_key', 'will_be_cleared');
echo "Set value: 'will_be_cleared'\n";

$clearWatch = $db->clearAndWatch($prefix . 'clear_watch_key');
echo "Cleared key and created watch\n";
echo "  isReady(): " . ($clearWatch->isReady() ? 'true' : 'false') . "\n";

// Set a new value to trigger the watch
$db->set($prefix . 'clear_watch_key', 'new_after_clear');
echo "\nSet new value after clear: 'new_after_clear'\n";

$attempts = 0;
while (!$clearWatch->isReady() && $attempts < 10) {
    usleep(10000);
    ++$attempts;
}
echo "  isReady() after new value: " . ($clearWatch->isReady() ? 'true' : 'false') . "\n\n";

// Watch limitations and best practices
echo "--- Watch Best Practices ---\n";
echo "1. Watches are one-shot: they fire once and must be recreated\n";
echo "2. Watches have a limit (typically 10,000 per database)\n";
echo "3. Watches may fire spuriously (always verify the change)\n";
echo "4. Cancel watches you no longer need to free resources\n";
echo "5. Use watches for coordination, not for high-frequency updates\n\n";

// Practical example: coordination
echo "--- Practical Example: Simple Coordination ---\n";
echo "Scenario: Process A waits for Process B to complete a task\n\n";

// Process A sets up a signal key
$db->set($prefix . 'signal', 'pending');
echo "Process A: Set signal to 'pending'\n";
echo "Process A: Would create watch and await() here\n";
echo "Process A: (In real app, this blocks until signal changes)\n\n";

// Process B completes and signals
$db->set($prefix . 'signal', 'completed');
echo "Process B: Task completed, set signal to 'completed'\n";
echo "Process A: Watch fires, continues execution\n\n";

// Multiple watches
echo "--- Multiple Watches ---\n";
echo "You can watch multiple keys simultaneously:\n\n";

$db->set($prefix . 'watch_a', '0');
$db->set($prefix . 'watch_b', '0');

$watchA = $db->setAndWatch($prefix . 'watch_a', '1');
$watchB = $db->setAndWatch($prefix . 'watch_b', '1');

echo "Created two watches\n";
echo "  Watch A ready: " . ($watchA->isReady() ? 'true' : 'false') . "\n";
echo "  Watch B ready: " . ($watchB->isReady() ? 'true' : 'false') . "\n";

// Trigger watch A
$db->set($prefix . 'watch_a', 'changed');
$attempts = 0;
while (!$watchA->isReady() && $attempts < 10) {
    usleep(10000);
    ++$attempts;
}

echo "\nAfter changing watch_a:\n";
echo "  Watch A ready: " . ($watchA->isReady() ? 'true' : 'false') . "\n";
echo "  Watch B ready: " . ($watchB->isReady() ? 'true' : 'false') . " (unchanged)\n";

// Trigger watch B
$db->set($prefix . 'watch_b', 'changed');
$attempts = 0;
while (!$watchB->isReady() && $attempts < 10) {
    usleep(10000);
    ++$attempts;
}

echo "\nAfter changing watch_b:\n";
echo "  Watch A ready: " . ($watchA->isReady() ? 'true' : 'false') . "\n";
echo "  Watch B ready: " . ($watchB->isReady() ? 'true' : 'false') . "\n\n";

// Cleanup
echo "--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
echo "\nNote: In production code, you would typically:\n";
echo "1. Call await() on watches to block until they fire\n";
echo "2. Handle spurious wakeups by checking actual values\n";
echo "3. Use try/finally to ensure watches are cancelled\n";
