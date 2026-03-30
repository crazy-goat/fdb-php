<?php

/**
 * Range Reads Example
 *
 * This script demonstrates FoundationDB range read operations:
 * - Lazy iteration with getRangeStartsWith() (streaming)
 * - Eager fetch with getRangeAllStartsWith() (all at once)
 * - RangeOptions for limiting and reversing results
 * - KeySelector for precise key selection
 * - Working with RangeResult objects
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\KeySelector;
use CrazyGoat\FoundationDB\RangeOptions;

echo "=== FoundationDB Range Reads ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

$prefix = 'example/range/';

// Cleanup
echo "Cleaning up existing test data...\n";
$db->clearRangeStartsWith($prefix);
echo "Done.\n\n";

// Set up test data
echo "--- Setting Up Test Data (15 keys) ---\n";
for ($i = 1; $i <= 15; $i++) {
    $key = sprintf("{$prefix}item/%02d", $i);
    $value = "Value for item {$i}";
    $db->set($key, $value);
    echo "  Set: {$key}\n";
}
echo "\n";

// Lazy iteration with getRangeStartsWith()
echo "--- Lazy Iteration (Streaming) ---\n";
echo "Using getRangeStartsWith() - results are fetched in chunks:\n";
$count = 0;
foreach ($db->getRangeStartsWith($prefix) as $kv) {
    ++$count;
    echo "  {$count}. {$kv->key} = " . substr($kv->value, 0, 20) . "...\n";
}
echo "Total items streamed: {$count}\n\n";

// Eager fetch with getRangeAllStartsWith()
echo "--- Eager Fetch (All at Once) ---\n";
echo "Using getRangeAllStartsWith() - all results fetched immediately:\n";
$allItems = $db->getRangeAllStartsWith($prefix);
echo "Fetched " . count($allItems) . " items at once\n";
foreach (array_slice($allItems, 0, 5) as $kv) {
    echo "  - {$kv->key}\n";
}
echo "  ... and " . (count($allItems) - 5) . " more\n\n";

// RangeOptions - Limit
echo "--- RangeOptions: Limit ---\n";
$limitedOptions = new RangeOptions(limit: 5);
$limitedResults = $db->getRangeAllStartsWith($prefix, $limitedOptions);
echo "With limit=5, fetched " . count($limitedResults) . " items:\n";
foreach ($limitedResults as $kv) {
    echo "  - {$kv->key}\n";
}
echo "\n";

// RangeOptions - Reverse
echo "--- RangeOptions: Reverse ---\n";
$reverseOptions = new RangeOptions(limit: 5, reverse: true);
$reverseResults = $db->getRangeAllStartsWith($prefix, $reverseOptions);
echo "With reverse=true, limit=5, items in reverse order:\n";
foreach ($reverseResults as $kv) {
    echo "  - {$kv->key}\n";
}
echo "\n";

// RangeOptions - StreamingMode
echo "--- RangeOptions: StreamingMode ---\n";
echo "Available streaming modes:\n";
echo "  - WantAll: Fetch all results immediately (default for getRangeAll*)\n";
echo "  - Iterator: Stream results in chunks (default for getRange*)\n";
echo "  - Exact: Request exact number of items\n";
echo "  - Small/Medium/Large: Different chunk sizes\n";
echo "  - Serial: Process results serially\n\n";

// KeySelector usage
echo "--- KeySelector Usage ---\n";
echo "KeySelectors allow precise key selection:\n\n";

// firstGreaterOrEqual - first key >= given key
$selector1 = KeySelector::firstGreaterOrEqual($prefix . 'item/05');
$key1 = $db->getKey($selector1);
echo "firstGreaterOrEqual('{$prefix}item/05'):\n";
echo "  Result: {$key1}\n\n";

// firstGreaterThan - first key > given key
$selector2 = KeySelector::firstGreaterThan($prefix . 'item/05');
$key2 = $db->getKey($selector2);
echo "firstGreaterThan('{$prefix}item/05'):\n";
echo "  Result: {$key2}\n\n";

// lastLessOrEqual - last key <= given key
$selector3 = KeySelector::lastLessOrEqual($prefix . 'item/10');
$key3 = $db->getKey($selector3);
echo "lastLessOrEqual('{$prefix}item/10'):\n";
echo "  Result: {$key3}\n\n";

// lastLessThan - last key < given key
$selector4 = KeySelector::lastLessThan($prefix . 'item/10');
$key4 = $db->getKey($selector4);
echo "lastLessThan('{$prefix}item/10'):\n";
echo "  Result: {$key4}\n\n";

// KeySelector with offset
$selector5 = KeySelector::firstGreaterOrEqual($prefix . 'item/05')->add(3);
$key5 = $db->getKey($selector5);
echo "firstGreaterOrEqual('{$prefix}item/05').add(3):\n";
echo "  Result: {$key5} (3 keys after item/05)\n\n";

// Range queries with KeySelectors
echo "--- Range Queries with KeySelectors ---\n";
$beginSelector = KeySelector::firstGreaterOrEqual($prefix . 'item/05');
$endSelector = KeySelector::firstGreaterOrEqual($prefix . 'item/10');
$rangeResults = $db->getRange($beginSelector, $endSelector);
echo "Range [item/05, item/10):\n";
foreach ($rangeResults as $kv) {
    echo "  - {$kv->key}\n";
}
echo "\n";

// Working with explicit key ranges
echo "--- Explicit Key Ranges ---\n";
$rangeItems = $db->getRangeAll(
    $prefix . 'item/03',
    $prefix . 'item/08',
);
echo "Range [item/03, item/08):\n";
foreach ($rangeItems as $kv) {
    echo "  - {$kv->key} = '{$kv->value}'\n";
}

// Cleanup
echo "\n--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
