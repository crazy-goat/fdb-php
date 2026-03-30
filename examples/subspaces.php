<?php

/**
 * Subspace Example
 *
 * This script demonstrates FoundationDB Subspace usage:
 * - Creating subspaces with tuple prefixes
 * - Packing and unpacking keys within subspaces
 * - Range queries within subspaces
 * - Nested subspaces
 * - contains() check
 * - Using subspaces with transactions
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Transaction;

echo "=== FoundationDB Subspace ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

// Cleanup
echo "Cleaning up existing test data...\n";
$db->clearRangeStartsWith("\x01"); // Clear our test prefix area
echo "Done.\n\n";

// Creating subspaces
echo "--- Creating Subspaces ---\n";

// Create a subspace with a tuple prefix
$usersSubspace = new Subspace(['users']);
echo "Created users subspace with raw prefix: " . bin2hex($usersSubspace->rawPrefix) . "\n";

// Create a subspace with raw prefix
$rawSubspace = new Subspace([], "\x01test_");
echo "Created raw subspace with prefix: " . bin2hex($rawSubspace->rawPrefix) . "\n\n";

// Packing keys
echo "--- Packing Keys ---\n";

// Pack a key within the users subspace
$userKey1 = $usersSubspace->pack([123]);
echo "Pack [123] in users subspace:\n";
echo "  Result: " . bin2hex($userKey1) . "\n";
$prefixHex = bin2hex($usersSubspace->rawPrefix);
$keySuffixHex = bin2hex(substr($userKey1, strlen($usersSubspace->rawPrefix)));
echo "  Raw prefix + tuple: " . $prefixHex . " + " . $keySuffixHex . "\n";

$userKey2 = $usersSubspace->pack([456, 'profile']);
echo "\nPack [456, 'profile'] in users subspace:\n";
echo "  Result: " . bin2hex($userKey2) . "\n";

// Pack with empty tuple (just the prefix)
$baseKey = $usersSubspace->pack([]);
echo "\nPack [] in users subspace (just prefix):\n";
echo "  Result: " . bin2hex($baseKey) . "\n\n";

// Unpacking keys
echo "--- Unpacking Keys ---\n";

// Unpack a key back to tuple
$unpacked1 = $usersSubspace->unpack($userKey1);
echo "Unpack " . bin2hex($userKey1) . ":\n";
echo "  Result: " . json_encode($unpacked1) . "\n";

$unpacked2 = $usersSubspace->unpack($userKey2);
echo "\nUnpack " . bin2hex($userKey2) . ":\n";
echo "  Result: " . json_encode($unpacked2) . "\n\n";

// Range queries within subspaces
echo "--- Range Queries Within Subspaces ---\n";

// Set up test data
$db->set($usersSubspace->pack([1, 'name']), 'Alice');
$db->set($usersSubspace->pack([1, 'email']), 'alice@example.com');
$db->set($usersSubspace->pack([2, 'name']), 'Bob');
$db->set($usersSubspace->pack([2, 'email']), 'bob@example.com');
$db->set($usersSubspace->pack([3, 'name']), 'Charlie');

echo "Created 5 user records\n\n";

// Get all users (entire subspace)
echo "All users (entire subspace):\n";
$allUsers = $db->getRangeAllStartsWith($usersSubspace->key());
foreach ($allUsers as $kv) {
    $unpacked = $usersSubspace->unpack($kv->key);
    echo "  User {$unpacked[0]} - {$unpacked[1]}: {$kv->value}\n";
}

// Get range for specific user
$user1Range = $usersSubspace->range([1]);
echo "\nUser 1 data (range query):\n";
$user1Data = $db->getRangeAll($user1Range[0], $user1Range[1]);
foreach ($user1Data as $kv) {
    $unpacked = $usersSubspace->unpack($kv->key);
    echo "  {$unpacked[1]}: {$kv->value}\n";
}
echo "\n";

// Nested subspaces
echo "--- Nested Subspaces ---\n";

// Create a nested subspace for user settings
$userSettingsSubspace = $usersSubspace->subspace('settings');
echo "Created nested subspace: users -> settings\n";
echo "  Raw prefix: " . bin2hex($userSettingsSubspace->rawPrefix) . "\n";

// Pack keys in nested subspace
$settingKey = $userSettingsSubspace->pack([1, 'theme']);
echo "\nPack [1, 'theme'] in users/settings subspace:\n";
echo "  Result: " . bin2hex($settingKey) . "\n";

// Unpack from nested subspace
$unpackedSetting = $userSettingsSubspace->unpack($settingKey);
echo "  Unpacked: " . json_encode($unpackedSetting) . "\n";

// Set some data in nested subspace
$db->set($userSettingsSubspace->pack([1, 'theme']), 'dark');
$db->set($userSettingsSubspace->pack([1, 'language']), 'en');
$db->set($userSettingsSubspace->pack([2, 'theme']), 'light');

echo "\nSettings for user 1:\n";
$user1SettingsRange = $userSettingsSubspace->range([1]);
$user1Settings = $db->getRangeAll($user1SettingsRange[0], $user1SettingsRange[1]);
foreach ($user1Settings as $kv) {
    $unpacked = $userSettingsSubspace->unpack($kv->key);
    echo "  {$unpacked[1]}: {$kv->value}\n";
}
echo "\n";

// contains() check
echo "--- Contains Check ---\n";

$testKey = $usersSubspace->pack([999]);
echo "Key " . bin2hex($testKey) . ":\n";
echo "  contains() in users subspace: " . ($usersSubspace->contains($testKey) ? 'true' : 'false') . "\n";
echo "  contains() in settings subspace: " . ($userSettingsSubspace->contains($testKey) ? 'true' : 'false') . "\n";

$settingKeyTest = $userSettingsSubspace->pack([1, 'notifications']);
echo "\nKey " . bin2hex($settingKeyTest) . ":\n";
$usersContains = $usersSubspace->contains($settingKeyTest) ? 'true' : 'false';
$settingsContains = $userSettingsSubspace->contains($settingKeyTest) ? 'true' : 'false';
echo "  contains() in users subspace: " . $usersContains . "\n";
echo "  contains() in settings subspace: " . $settingsContains . "\n\n";

// Using subspaces with transactions
echo "--- Subspaces with Transactions ---\n";

$result = $db->transact(function (Transaction $tr) use ($usersSubspace): array {
    // Read all users within transaction
    $users = [];
    $range = $tr->getRangeStartsWith($usersSubspace->key());

    foreach ($range as $kv) {
        if ($usersSubspace->contains($kv->key)) {
            $unpacked = $usersSubspace->unpack($kv->key);
            $users[] = [
                'id' => $unpacked[0],
                'field' => $unpacked[1] ?? null,
                'value' => $kv->value,
            ];
        }
    }

    // Add a new user
    $tr->set($usersSubspace->pack([4, 'name']), 'Diana');

    return $users;
});

echo "Users found in transaction: " . count($result) . "\n";
foreach ($result as $user) {
    echo "  User {$user['id']} - {$user['field']}: {$user['value']}\n";
}

// Verify new user was added
$newUser = $db->get($usersSubspace->pack([4, 'name']));
echo "\nNew user added in transaction: {$newUser}\n\n";

// Subspace with raw prefix only
echo "--- Raw Prefix Subspace ---\n";
$rawPrefixSubspace = new Subspace([], 'myapp_data_');
echo "Created subspace with raw prefix 'myapp_data_'\n";

$rawKey = $rawPrefixSubspace->pack(['item1']);
echo "Pack ['item1']: " . bin2hex($rawKey) . "\n";

// Unpack from raw prefix subspace
$unpackedRaw = $rawPrefixSubspace->unpack($rawKey);
echo "Unpack: " . json_encode($unpackedRaw) . "\n\n";

// Cleanup
echo "--- Cleanup ---\n";
$db->clearRangeStartsWith($usersSubspace->key());
$db->clearRangeStartsWith($rawPrefixSubspace->key());
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
