<?php

/**
 * Tuple Layer Example
 *
 * This script demonstrates FoundationDB tuple encoding/decoding:
 * - Packing and unpacking basic tuples
 * - Sort order preservation
 * - Tuple comparison
 * - Tuple ranges for prefix queries
 * - Special types: Bytes, SingleFloat, Uuid
 * - Nested tuples
 * - Versionstamp (incomplete)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Tuple\Bytes;
use CrazyGoat\FoundationDB\Tuple\SingleFloat;
use CrazyGoat\FoundationDB\Tuple\Tuple;
use CrazyGoat\FoundationDB\Tuple\Uuid;
use CrazyGoat\FoundationDB\Tuple\Versionstamp;

echo "=== FoundationDB Tuple Layer ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

// Basic tuple packing and unpacking
echo "--- Basic Tuple Packing/Unpacking ---\n";

// Pack a simple tuple
$tuple1 = ['user', 123, 'active'];
$packed1 = Tuple::pack($tuple1);
echo "Original: " . json_encode($tuple1) . "\n";
echo "Packed (hex): " . bin2hex($packed1) . "\n";
echo "Packed length: " . strlen($packed1) . " bytes\n";

// Unpack it back
$unpacked1 = Tuple::unpack($packed1);
echo "Unpacked: " . json_encode($unpacked1) . "\n\n";

// Different data types
echo "--- Tuple Data Types ---\n";
$mixedTuple = [
    null,                           // Null
    true,                           // Boolean true
    false,                          // Boolean false
    42,                             // Integer
    -100,                           // Negative integer
    'hello world',                  // String
    3.14159,                        // Double float
];

$packedMixed = Tuple::pack($mixedTuple);
$unpackedMixed = Tuple::unpack($packedMixed);

echo "Original mixed tuple:\n";
foreach ($mixedTuple as $i => $val) {
    $type = gettype($val);
    $display = $val === null ? 'null' : ($type === 'boolean' ? ($val ? 'true' : 'false') : $val);
    echo "  [{$i}] {$type}: {$display}\n";
}

echo "\nUnpacked mixed tuple:\n";
foreach ($unpackedMixed as $i => $val) {
    $type = gettype($val);
    $display = $val === null ? 'null' : ($type === 'boolean' ? ($val ? 'true' : 'false') : $val);
    echo "  [{$i}] {$type}: {$display}\n";
}
echo "\n";

// Sort order preservation
echo "--- Sort Order Preservation ---\n";
echo "Tuples preserve natural sort order of elements:\n\n";

$tuples = [
    ['a', 1],
    ['a', 2],
    ['a', 10],
    ['b', 1],
    ['b', 5],
];

echo "Original tuples:\n";
foreach ($tuples as $t) {
    echo "  " . json_encode($t) . "\n";
}

// Pack and sort
$packedTuples = array_map(fn($t) => Tuple::pack($t), $tuples);
sort($packedTuples);

echo "\nAfter packing and sorting:\n";
foreach ($packedTuples as $p) {
    echo "  " . json_encode(Tuple::unpack($p)) . "\n";
}
echo "\n";

// Tuple comparison
echo "--- Tuple Comparison ---\n";
$tupleA = ['user', 100];
$tupleB = ['user', 200];
$tupleC = ['admin', 50];

$result1 = Tuple::compare($tupleA, $tupleB);
$result2 = Tuple::compare($tupleB, $tupleA);
$result3 = Tuple::compare($tupleA, $tupleC);

echo "compare(['user', 100], ['user', 200]) = {$result1} (negative: first < second)\n";
echo "compare(['user', 200], ['user', 100]) = {$result2} (positive: first > second)\n";
echo "compare(['user', 100], ['admin', 50]) = {$result3} (positive: 'user' > 'admin')\n\n";

// Tuple ranges
echo "--- Tuple Ranges ---\n";
$prefix = 'example/tuple/';
$db->clearRangeStartsWith($prefix);

// Create keys using tuple encoding
$keys = [
    Tuple::pack(['user', 1], $prefix),
    Tuple::pack(['user', 2], $prefix),
    Tuple::pack(['user', 10], $prefix),
    Tuple::pack(['admin', 1], $prefix),
    Tuple::pack(['admin', 5], $prefix),
];

echo "Created keys with tuple encoding:\n";
foreach ($keys as $key) {
    $db->set($key, 'some value');
    $unpacked = Tuple::unpack($key, strlen($prefix));
    echo "  " . json_encode($unpacked) . " -> " . bin2hex($key) . "\n";
}

// Get range for all 'user' tuples
$userRange = Tuple::range(['user']);
$begin = $prefix . $userRange[0];
$end = $prefix . $userRange[1];

echo "\nRange for 'user' prefix:\n";
echo "  Begin: " . json_encode(Tuple::unpack($begin, strlen($prefix))) . "\n";
echo "  End: " . json_encode(Tuple::unpack($end, strlen($prefix))) . "\n";

$userResults = $db->getRangeAll($begin, $end);
echo "\nResults in 'user' range:\n";
foreach ($userResults as $kv) {
    $unpacked = Tuple::unpack($kv->key, strlen($prefix));
    echo "  " . json_encode($unpacked) . "\n";
}

$db->clearRangeStartsWith($prefix);
echo "\n";

// Special types
echo "--- Special Tuple Types ---\n\n";

// Bytes type - for raw binary data
echo "Bytes type (raw binary data):\n";
$binaryData = new Bytes("\x00\x01\x02\x03\xff");
$bytesTuple = [$binaryData];
$packedBytes = Tuple::pack($bytesTuple);
$unpackedBytes = Tuple::unpack($packedBytes);
echo "  Original: " . bin2hex($binaryData->data) . "\n";
echo "  Unpacked: " . bin2hex($unpackedBytes[0]->data) . "\n\n";

// SingleFloat type
echo "SingleFloat type:\n";
$float = new SingleFloat(1.5);
$floatTuple = [$float];
$packedFloat = Tuple::pack($floatTuple);
$unpackedFloat = Tuple::unpack($packedFloat);
echo "  Original: {$float->value}\n";
echo "  Unpacked: {$unpackedFloat[0]->value}\n\n";

// UUID type
echo "UUID type:\n";
$uuidBytes = random_bytes(16);
$uuid = new Uuid($uuidBytes);
$uuidTuple = [$uuid];
$packedUuid = Tuple::pack($uuidTuple);
$unpackedUuid = Tuple::unpack($packedUuid);
echo "  Original: " . bin2hex($uuid->bytes) . "\n";
echo "  Unpacked: " . bin2hex($unpackedUuid[0]->bytes) . "\n\n";

// Nested tuples
echo "--- Nested Tuples ---\n";
$nested = [
    'outer1',
    ['inner1', 'inner2'],
    'outer2',
    ['nested', ['deeply', 'nested'], 'data'],
];

echo "Original nested: " . json_encode($nested) . "\n";
$packedNested = Tuple::pack($nested);
echo "Packed length: " . strlen($packedNested) . " bytes\n";
$unpackedNested = Tuple::unpack($packedNested);
echo "Unpacked: " . json_encode($unpackedNested) . "\n\n";

// Versionstamp
echo "--- Versionstamp ---\n";
echo "Versionstamp represents a unique transaction identifier.\n";
echo "It consists of:\n";
echo "  - 10 bytes: transaction version (committed version)\n";
echo "  - 2 bytes: user version (for ordering within transaction)\n\n";

// Complete versionstamp
$completeVs = new Versionstamp(str_repeat("\x01", 10), 42);
echo "Complete versionstamp:\n";
echo "  trVersion: " . bin2hex($completeVs->trVersion) . "\n";
echo "  userVersion: {$completeVs->userVersion}\n";
echo "  isComplete: " . ($completeVs->isComplete() ? 'true' : 'false') . "\n\n";

// Incomplete versionstamp (for use in mutations)
$incompleteVs = Versionstamp::incomplete(0);
echo "Incomplete versionstamp:\n";
echo "  trVersion: " . bin2hex($incompleteVs->trVersion) . "\n";
echo "  userVersion: {$incompleteVs->userVersion}\n";
echo "  isComplete: " . ($incompleteVs->isComplete() ? 'true' : 'false') . "\n";
echo "  Note: Incomplete versionstamp is filled in by FDB on commit\n\n";

// Cleanup
echo "--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "Test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
