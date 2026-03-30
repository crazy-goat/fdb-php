<?php

/**
 * Basic CRUD Operations Example
 *
 * This script demonstrates fundamental FoundationDB operations:
 * - Setting API version and opening a database
 * - Setting key-value pairs
 * - Reading values back
 * - Clearing individual keys
 * - Clearing ranges of keys
 * - Handling missing keys (null values)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;

echo "=== FoundationDB Basic CRUD Operations ===\n\n";

// Set API version (required before any other operations)
FoundationDB::apiVersion(730);
echo "API version set to 730\n";

// Open database connection
$db = FoundationDB::open();
echo "Database opened successfully\n\n";

// Define a test prefix to avoid conflicts with other data
$prefix = 'example/crud/';

// Clean up any existing test data first
echo "Cleaning up existing test data...\n";
$db->clearRangeStartsWith($prefix);
echo "Done.\n\n";

// SET operations
echo "--- SET Operations ---\n";
$db->set($prefix . 'key1', 'value1');
echo "Set: {$prefix}key1 = 'value1'\n";

$db->set($prefix . 'key2', 'value2');
echo "Set: {$prefix}key2 = 'value2'\n";

$db->set($prefix . 'key3', 'value3');
echo "Set: {$prefix}key3 = 'value3'\n";

$db->set($prefix . 'nested/keyA', 'nested value A');
echo "Set: {$prefix}nested/keyA = 'nested value A'\n";

$db->set($prefix . 'nested/keyB', 'nested value B');
echo "Set: {$prefix}nested/keyB = 'nested value B'\n\n";

// GET operations
echo "--- GET Operations ---\n";
$value1 = $db->get($prefix . 'key1');
echo "Get: {$prefix}key1 = " . ($value1 === null ? 'null' : "'{$value1}'") . "\n";

$value2 = $db->get($prefix . 'key2');
echo "Get: {$prefix}key2 = " . ($value2 === null ? 'null' : "'{$value2}'") . "\n";

// Try to get a non-existent key
echo "\n--- Missing Key Handling ---\n";
$missing = $db->get($prefix . 'nonexistent');
echo "Get: {$prefix}nonexistent = " . ($missing === null ? 'null (key does not exist)' : "'{$missing}'") . "\n\n";

// CLEAR operations (individual keys)
echo "--- CLEAR Individual Keys ---\n";
$db->clear($prefix . 'key2');
echo "Cleared: {$prefix}key2\n";

$value2AfterClear = $db->get($prefix . 'key2');
echo "Get after clear: {$prefix}key2 = " . ($value2AfterClear === null ? 'null' : "'{$value2AfterClear}'") . "\n\n";

// CLEAR RANGE operations
echo "--- CLEAR Range Operations ---\n";
echo "Reading all keys with prefix '{$prefix}nested/'...\n";
$nestedKeys = $db->getRangeAllStartsWith($prefix . 'nested/');
echo "Found " . count($nestedKeys) . " keys:\n";
foreach ($nestedKeys as $kv) {
    echo "  - {$kv->key} = '{$kv->value}'\n";
}

// Clear the entire nested range
$db->clearRangeStartsWith($prefix . 'nested/');
echo "\nCleared range: {$prefix}nested/*\n";

$nestedKeysAfter = $db->getRangeAllStartsWith($prefix . 'nested/');
echo "Keys remaining in nested/: " . count($nestedKeysAfter) . "\n\n";

// CLEAR RANGE with explicit begin/end
echo "--- CLEAR Range (Explicit Begin/End) ---\n";
// Set up some sequential keys
for ($i = 1; $i <= 5; $i++) {
    $db->set($prefix . "seq/key{$i}", "value{$i}");
}
echo "Created 5 sequential keys\n";

// Read them back
$allSeqKeys = $db->getRangeAllStartsWith($prefix . 'seq/');
echo "Before clear: " . count($allSeqKeys) . " keys\n";

// Clear only keys 2-4 (key2 <= key < key5)
$db->clearRange($prefix . 'seq/key2', $prefix . 'seq/key5');
echo "Cleared range: [{$prefix}seq/key2, {$prefix}seq/key5)\n";

$remainingKeys = $db->getRangeAllStartsWith($prefix . 'seq/');
echo "After clear: " . count($remainingKeys) . " keys remaining:\n";
foreach ($remainingKeys as $kv) {
    echo "  - {$kv->key}\n";
}

// Cleanup
echo "\n--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
