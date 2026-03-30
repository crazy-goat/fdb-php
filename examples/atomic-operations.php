<?php

/**
 * Atomic Operations Example
 *
 * This script demonstrates FoundationDB atomic operations:
 * - Counter with add()
 * - Reading counter value
 * - Bitwise operations (bitXor for toggle)
 * - compareAndClear
 * - Clean up
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Transaction;

echo "=== FoundationDB Atomic Operations ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

$prefix = 'example/atomic/';

// Cleanup
echo "Cleaning up existing test data...\n";
$db->clearRangeStartsWith($prefix);
echo "Done.\n\n";

// Counter with add()
echo "--- Atomic Counter with add() ---\n";
echo "Atomic operations are performed without reading the value first,\n";
echo "making them safe for concurrent access.\n\n";

// Initialize counter
$db->set($prefix . 'counter', pack('J', 0)); // 64-bit unsigned integer
echo "Initialized counter to 0\n";

// Atomically add values
$db->add($prefix . 'counter', pack('J', 10));
echo "Added 10 (atomic)\n";

$db->add($prefix . 'counter', pack('J', 5));
echo "Added 5 (atomic)\n";

$db->add($prefix . 'counter', pack('J', 100));
echo "Added 100 (atomic)\n";

// Read the counter value
$counterValue = $db->get($prefix . 'counter');
if ($counterValue !== null) {
    $unpacked = unpack('J', $counterValue);
    if ($unpacked !== false) {
        echo "\nFinal counter value: {$unpacked[1]}\n";
    }
}
echo "Expected: 115 (10 + 5 + 100 = 115)\n\n";

// Demonstrating concurrent safety
echo "--- Concurrent Safety Demonstration ---\n";
echo "Simulating concurrent increments:\n";

// Reset counter
$db->set($prefix . 'concurrent_counter', pack('J', 0));

// Simulate multiple concurrent transactions adding to the same counter
$additions = [1, 2, 3, 4, 5];
foreach ($additions as $amount) {
    $db->transact(function (Transaction $tr) use ($prefix, $amount): void {
        // Each transaction atomically adds without reading
        $tr->add($prefix . 'concurrent_counter', pack('J', $amount));
    });
    echo "  Transaction added {$amount}\n";
}

$finalValue = $db->get($prefix . 'concurrent_counter');
if ($finalValue !== null) {
    $unpacked = unpack('J', $finalValue);
    if ($unpacked !== false) {
        echo "\nFinal value after concurrent adds: {$unpacked[1]}\n";
        echo "Expected: 15 (1+2+3+4+5 = 15)\n";
        echo "Note: With atomic operations, all increments are preserved!\n";
    }
}
echo "\n";

// Bitwise operations
echo "--- Bitwise Operations ---\n";

// bitOr - sets bits
$db->set($prefix . 'flags', pack('C', 0b00000000));
echo "Initialized flags: 00000000 (binary)\n";

$db->bitOr($prefix . 'flags', pack('C', 0b00000001)); // Set bit 0
echo "After bitOr(00000001): set bit 0\n";

$db->bitOr($prefix . 'flags', pack('C', 0b00000100)); // Set bit 2
echo "After bitOr(00000100): set bit 2\n";

$db->bitOr($prefix . 'flags', pack('C', 0b00010000)); // Set bit 4
echo "After bitOr(00010000): set bit 4\n";

$flagsValue = $db->get($prefix . 'flags');
if ($flagsValue !== null) {
    $unpacked = unpack('C', $flagsValue);
    if ($unpacked !== false) {
        echo "Final flags: " . sprintf('%08b', $unpacked[1]) . " (binary) = {$unpacked[1]} (decimal)\n";
        echo "Expected: 00010101 = 21\n";
    }
}
echo "\n";

// bitAnd - clears bits (using mask)
echo "--- bitAnd for Clearing Bits ---\n";
$db->set($prefix . 'mask_test', pack('C', 0b11111111));
echo "Initialized: 11111111\n";

// To clear bit 1, AND with 11111101
$db->bitAnd($prefix . 'mask_test', pack('C', 0b11111101));
$maskValue = $db->get($prefix . 'mask_test');
if ($maskValue !== null) {
    $unpacked = unpack('C', $maskValue);
    if ($unpacked !== false) {
        echo "After bitAnd(11111101): " . sprintf('%08b', $unpacked[1]) . "\n";
    }
}
echo "\n";

// bitXor - toggle bits
echo "--- bitXor for Toggling Bits ---\n";
$db->set($prefix . 'toggle', pack('C', 0b00000000));
echo "Initialized: 00000000\n";

// Toggle bit 0
$db->bitXor($prefix . 'toggle', pack('C', 0b00000001));
$toggle1 = $db->get($prefix . 'toggle');
if ($toggle1 !== null) {
    $unpacked = unpack('C', $toggle1);
    if ($unpacked !== false) {
        echo "After bitXor(00000001): " . sprintf('%08b', $unpacked[1]) . " (bit 0 ON)\n";
    }
}

// Toggle bit 0 again (turns it off)
$db->bitXor($prefix . 'toggle', pack('C', 0b00000001));
$toggle2 = $db->get($prefix . 'toggle');
if ($toggle2 !== null) {
    $unpacked = unpack('C', $toggle2);
    if ($unpacked !== false) {
        echo "After bitXor(00000001): " . sprintf('%08b', $unpacked[1]) . " (bit 0 OFF)\n";
    }
}

// Toggle multiple bits
$db->bitXor($prefix . 'toggle', pack('C', 0b00000110)); // Toggle bits 1 and 2
$toggle3 = $db->get($prefix . 'toggle');
if ($toggle3 !== null) {
    $unpacked = unpack('C', $toggle3);
    if ($unpacked !== false) {
        echo "After bitXor(00000110): " . sprintf('%08b', $unpacked[1]) . " (bits 1,2 ON)\n";
    }
}
echo "\n";

// compareAndClear
echo "--- compareAndClear ---\n";
echo "Atomically clears a key only if its value matches the expected value.\n\n";

$db->set($prefix . 'conditional', 'expected_value');
echo "Set key to 'expected_value'\n";

// Try to clear with wrong value (should not clear)
$db->compareAndClear($prefix . 'conditional', 'wrong_value');
$stillThere = $db->get($prefix . 'conditional');
$stillThereStr = $stillThere === null ? 'null (cleared)' : "'{$stillThere}' (still present)";
echo "After compareAndClear('wrong_value'): " . $stillThereStr . "\n";

// Now clear with correct value
$db->compareAndClear($prefix . 'conditional', 'expected_value');
$nowGone = $db->get($prefix . 'conditional');
echo "After compareAndClear('expected_value'): " . ($nowGone === null ? 'null (cleared)' : "'{$nowGone}'") . "\n";
echo "\n";

// max and min operations
echo "--- max and min Operations ---\n";

// max - keeps the larger value
$db->set($prefix . 'max_test', pack('J', 50));
echo "Initialized max_test to 50\n";

$db->max($prefix . 'max_test', pack('J', 30)); // 30 < 50, no change
echo "After max(30): value stays 50 (30 < 50)\n";

$db->max($prefix . 'max_test', pack('J', 100)); // 100 > 50, updates
echo "After max(100): value becomes 100 (100 > 50)\n";

$maxValue = $db->get($prefix . 'max_test');
if ($maxValue !== null) {
    $unpacked = unpack('J', $maxValue);
    if ($unpacked !== false) {
        echo "Final max value: {$unpacked[1]}\n";
    }
}

// min - keeps the smaller value
$db->set($prefix . 'min_test', pack('J', 50));
echo "\nInitialized min_test to 50\n";

$db->min($prefix . 'min_test', pack('J', 100)); // 100 > 50, no change
echo "After min(100): value stays 50 (100 > 50)\n";

$db->min($prefix . 'min_test', pack('J', 30)); // 30 < 50, updates
echo "After min(30): value becomes 30 (30 < 50)\n";

$minValue = $db->get($prefix . 'min_test');
if ($minValue !== null) {
    $unpacked = unpack('J', $minValue);
    if ($unpacked !== false) {
        echo "Final min value: {$unpacked[1]}\n";
    }
}
echo "\n";

// Practical example: visit counter
echo "--- Practical Example: Visit Counter ---\n";
$db->set($prefix . 'visits', pack('J', 0));
echo "Initialized visit counter to 0\n";

// Simulate page visits
$visits = [1, 1, 1, 1, 1]; // 5 visits
foreach ($visits as $i => $_) {
    $db->add($prefix . 'visits', pack('J', 1));
    echo "  Visit " . ($i + 1) . " recorded\n";
}

$totalVisits = $db->get($prefix . 'visits');
if ($totalVisits !== null) {
    $unpacked = unpack('J', $totalVisits);
    if ($unpacked !== false) {
        echo "\nTotal visits: {$unpacked[1]}\n";
    }
}

// Cleanup
echo "\n--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
