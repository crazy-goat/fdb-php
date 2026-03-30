<?php

/**
 * Atomic Operations Example
 *
 * This script demonstrates FoundationDB atomic operations:
 * - Counter with add() and getInt()
 * - Bitwise operations (bitOr, bitAnd, bitXor)
 * - max() and min() for keeping extremes
 * - compareAndClear for conditional deletion
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

// Counter with add() — now takes int directly, no pack() needed
echo "--- Atomic Counter with add() ---\n";
echo "add() accepts an int — no more pack('P', ...) boilerplate!\n\n";

$db->set($prefix . 'counter', pack('P', 0));
echo "Initialized counter to 0\n";

$db->add($prefix . 'counter', 10);
echo "Added 10 (atomic)\n";

$db->add($prefix . 'counter', 5);
echo "Added 5 (atomic)\n";

$db->add($prefix . 'counter', 100);
echo "Added 100 (atomic)\n";

// Read the counter with getInt() — no more unpack() needed
$value = $db->getInt($prefix . 'counter');
echo "\nFinal counter value: {$value}\n";
echo "Expected: 115 (10 + 5 + 100 = 115)\n\n";

// Concurrent safety
echo "--- Concurrent Safety Demonstration ---\n";
$db->set($prefix . 'concurrent', pack('P', 0));

$additions = [1, 2, 3, 4, 5];
foreach ($additions as $amount) {
    $db->transact(function (Transaction $tr) use ($prefix, $amount): void {
        $tr->add($prefix . 'concurrent', $amount);
    });
    echo "  Transaction added {$amount}\n";
}

$finalValue = $db->getInt($prefix . 'concurrent');
echo "\nFinal value: {$finalValue}\n";
echo "Expected: 15 (1+2+3+4+5)\n\n";

// Bitwise operations — also take int now
echo "--- Bitwise Operations ---\n";

$db->set($prefix . 'flags', pack('P', 0));
echo "Initialized flags: 0\n";

$db->bitOr($prefix . 'flags', 0b00000001); // Set bit 0
echo "After bitOr(0b00000001): set bit 0\n";

$db->bitOr($prefix . 'flags', 0b00000100); // Set bit 2
echo "After bitOr(0b00000100): set bit 2\n";

$db->bitOr($prefix . 'flags', 0b00010000); // Set bit 4
echo "After bitOr(0b00010000): set bit 4\n";

$flags = $db->getInt($prefix . 'flags');
echo "Final flags: " . sprintf('%08b', $flags) . " = {$flags}\n";
echo "Expected: 00010101 = 21\n\n";

// bitAnd — clear specific bits
echo "--- bitAnd for Clearing Bits ---\n";
$db->set($prefix . 'mask', pack('P', 0b11111111));
echo "Initialized: 11111111\n";

$db->bitAnd($prefix . 'mask', 0b11111101); // Clear bit 1
$maskValue = $db->getInt($prefix . 'mask');
echo "After bitAnd(11111101): " . sprintf('%08b', $maskValue) . "\n";
echo "Expected: 11111101\n\n";

// bitXor — toggle bits
echo "--- bitXor for Toggling ---\n";
$db->set($prefix . 'toggle', pack('P', 0));
echo "Initialized: 0\n";

$db->bitXor($prefix . 'toggle', 0b00000001);
echo "After bitXor(1): " . sprintf('%08b', $db->getInt($prefix . 'toggle')) . " (bit 0 ON)\n";

$db->bitXor($prefix . 'toggle', 0b00000001);
echo "After bitXor(1): " . sprintf('%08b', $db->getInt($prefix . 'toggle')) . " (bit 0 OFF)\n";

$db->bitXor($prefix . 'toggle', 0b00000110);
echo "After bitXor(110): " . sprintf('%08b', $db->getInt($prefix . 'toggle')) . " (bits 1,2 ON)\n\n";

// max and min — also take int
echo "--- max() and min() ---\n";

$db->set($prefix . 'high_score', pack('P', 50));
echo "Initialized high_score to 50\n";

$db->max($prefix . 'high_score', 30);
echo "After max(30): " . $db->getInt($prefix . 'high_score') . " (30 < 50, no change)\n";

$db->max($prefix . 'high_score', 100);
echo "After max(100): " . $db->getInt($prefix . 'high_score') . " (100 > 50, updated)\n\n";

$db->set($prefix . 'lowest', pack('P', 50));
echo "Initialized lowest to 50\n";

$db->min($prefix . 'lowest', 100);
echo "After min(100): " . $db->getInt($prefix . 'lowest') . " (100 > 50, no change)\n";

$db->min($prefix . 'lowest', 30);
echo "After min(30): " . $db->getInt($prefix . 'lowest') . " (30 < 50, updated)\n\n";

// compareAndClear — still uses string (raw byte comparison)
echo "--- compareAndClear ---\n";
$db->set($prefix . 'conditional', 'expected_value');
echo "Set key to 'expected_value'\n";

$db->compareAndClear($prefix . 'conditional', 'wrong_value');
$stillThere = $db->get($prefix . 'conditional');
echo "After compareAndClear('wrong_value'): " . ($stillThere === null ? 'cleared' : "'{$stillThere}'") . "\n";

$db->compareAndClear($prefix . 'conditional', 'expected_value');
$nowGone = $db->get($prefix . 'conditional');
echo "After compareAndClear('expected_value'): " . ($nowGone === null ? 'cleared' : "'{$nowGone}'") . "\n\n";

// getInt() returns null for missing keys
echo "--- getInt() with missing keys ---\n";
$missing = $db->getInt($prefix . 'nonexistent');
echo "getInt('nonexistent'): " . ($missing === null ? 'null' : $missing) . "\n\n";

// Practical example
echo "--- Practical Example: Page View Counter ---\n";
$db->set($prefix . 'views', pack('P', 0));

for ($i = 1; $i <= 5; $i++) {
    $db->add($prefix . 'views', 1);
    echo "  Visit {$i} recorded\n";
}

echo "\nTotal views: " . $db->getInt($prefix . 'views') . "\n";

// Cleanup
echo "\n--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
