<?php

/**
 * Directory Layer Example
 *
 * This script demonstrates FoundationDB DirectoryLayer for hierarchical
 * namespace management:
 * - Creating directories
 * - Using directories as subspaces (pack/unpack)
 * - Listing directories
 * - Checking existence
 * - Moving directories
 * - Removing directories
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\FoundationDB;

echo "=== FoundationDB Directory Layer ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

// Create a directory layer instance
$directoryLayer = new DirectoryLayer();
echo "Created DirectoryLayer instance\n\n";

// Cleanup any existing test directories first
echo "--- Cleaning Up Existing Test Directories ---\n";
try {
    if ($directoryLayer->exists($db, ['example', 'app1'])) {
        $directoryLayer->removeIfExists($db, ['example', 'app1']);
        echo "Removed existing example/app1 directory\n";
    }
    if ($directoryLayer->exists($db, ['example', 'app2'])) {
        $directoryLayer->removeIfExists($db, ['example', 'app2']);
        echo "Removed existing example/app2 directory\n";
    }
    if ($directoryLayer->exists($db, ['example', 'moved'])) {
        $directoryLayer->removeIfExists($db, ['example', 'moved']);
        echo "Removed existing example/moved directory\n";
    }
    if ($directoryLayer->exists($db, ['example'])) {
        $directoryLayer->removeIfExists($db, ['example']);
        echo "Removed existing example directory\n";
    }
} catch (\Exception $e) {
    echo "Note: " . $e->getMessage() . "\n";
}
echo "Cleanup complete.\n\n";

// Creating directories
echo "--- Creating Directories ---\n";

// Create a simple directory
$app1Dir = $directoryLayer->create($db, ['example', 'app1']);
echo "Created directory: example/app1\n";
echo "  Path: " . json_encode($app1Dir->getPath()) . "\n";
echo "  Layer: '{$app1Dir->getLayer()}'\n";
echo "  Raw prefix: " . bin2hex($app1Dir->rawPrefix) . "\n\n";

// Create a directory with a layer
$app2Dir = $directoryLayer->create($db, ['example', 'app2'], 'my-app-layer');
echo "Created directory: example/app2 with layer 'my-app-layer'\n";
echo "  Layer: '{$app2Dir->getLayer()}'\n\n";

// Create nested directories (parent is created automatically)
$usersDir = $directoryLayer->create($db, ['example', 'app1', 'users']);
echo "Created nested directory: example/app1/users\n";
echo "  (Parent directories are created automatically)\n\n";

// Using directories as subspaces
echo "--- Using Directories as Subspaces ---\n";

// Pack keys within the directory
$userKey1 = $app1Dir->pack(['user', 123]);
echo "Pack ['user', 123] in example/app1:\n";
echo "  Result: " . bin2hex($userKey1) . "\n";

// Store data using the directory
$db->set($app1Dir->pack(['config', 'version']), '1.0.0');
$db->set($app1Dir->pack(['config', 'debug']), 'false');
$db->set($usersDir->pack([1, 'name']), 'Alice');
$db->set($usersDir->pack([1, 'email']), 'alice@example.com');
$db->set($usersDir->pack([2, 'name']), 'Bob');

echo "\nStored data in directories:\n";
echo "  example/app1/config/version = '1.0.0'\n";
echo "  example/app1/config/debug = 'false'\n";
echo "  example/app1/users/[1, 'name'] = 'Alice'\n";
echo "  example/app1/users/[1, 'email'] = 'alice@example.com'\n";
echo "  example/app1/users/[2, 'name'] = 'Bob'\n\n";

// Read data back
$version = $db->get($app1Dir->pack(['config', 'version']));
$debug = $db->get($app1Dir->pack(['config', 'debug']));
echo "Read config from example/app1:\n";
echo "  version: {$version}\n";
echo "  debug: {$debug}\n\n";

// Unpack keys
$storedKey = $app1Dir->pack(['user', 456, 'profile']);
$unpacked = $app1Dir->unpack($storedKey);
echo "Unpack key in example/app1:\n";
echo "  Key: " . bin2hex($storedKey) . "\n";
echo "  Unpacked: " . json_encode($unpacked) . "\n\n";

// Range queries within directories
echo "--- Range Queries Within Directories ---\n";

// Get all config entries
$configRange = $app1Dir->range(['config']);
echo "Config entries in example/app1:\n";
$configData = $db->getRangeAll($configRange[0], $configRange[1]);
foreach ($configData as $kv) {
    $unpacked = $app1Dir->unpack($kv->key);
    echo "  " . json_encode($unpacked) . " = '{$kv->value}'\n";
}

// Get all users
$usersRange = $usersDir->range([]);
echo "\nUsers in example/app1/users:\n";
$usersData = $db->getRangeAll($usersRange[0], $usersRange[1]);
foreach ($usersData as $kv) {
    $unpacked = $usersDir->unpack($kv->key);
    echo "  User {$unpacked[0]} - {$unpacked[1]}: {$kv->value}\n";
}
echo "\n";

// Listing directories
echo "--- Listing Directories ---\n";

// List root directories
$rootDirs = $directoryLayer->list($db);
echo "Directories at root level:\n";
foreach ($rootDirs as $name) {
    echo "  - {$name}\n";
}

// List subdirectories
$exampleDirs = $directoryLayer->list($db, ['example']);
echo "\nDirectories under 'example':\n";
foreach ($exampleDirs as $name) {
    echo "  - {$name}\n";
}

$app1Subdirs = $directoryLayer->list($db, ['example', 'app1']);
echo "\nDirectories under 'example/app1':\n";
foreach ($app1Subdirs as $name) {
    echo "  - {$name}\n";
}
echo "\n";

// Checking existence
echo "--- Checking Directory Existence ---\n";
$exists1 = $directoryLayer->exists($db, ['example', 'app1']);
$exists2 = $directoryLayer->exists($db, ['example', 'nonexistent']);
echo "exists(['example', 'app1']): " . ($exists1 ? 'true' : 'false') . "\n";
echo "exists(['example', 'nonexistent']): " . ($exists2 ? 'true' : 'false') . "\n\n";

// Opening existing directories
echo "--- Opening Existing Directories ---\n";
try {
    $openedDir = $directoryLayer->open($db, ['example', 'app1']);
    echo "Opened existing directory: example/app1\n";
    echo "  Path: " . json_encode($openedDir->getPath()) . "\n";
    echo "  Same as created: " . ($openedDir->rawPrefix === $app1Dir->rawPrefix ? 'true' : 'false') . "\n";
} catch (\Exception $e) {
    echo "Error opening directory: " . $e->getMessage() . "\n";
}

// Try to open non-existent directory
echo "\nTrying to open non-existent directory:\n";
try {
    $directoryLayer->open($db, ['example', 'nonexistent']);
    echo "  Unexpectedly succeeded\n";
} catch (\Exception $e) {
    echo "  Expected error: " . $e->getMessage() . "\n";
}
echo "\n";

// Moving directories
echo "--- Moving Directories ---\n";

// Create a directory to move
$toMove = $directoryLayer->create($db, ['example', 'app1', 'temp']);
echo "Created directory: example/app1/temp\n";

// Store some data in it
$db->set($toMove->pack(['data']), 'temporary data');
echo "Stored data in temp directory\n";

// Move the directory
$movedDir = $directoryLayer->move($db, ['example', 'app1', 'temp'], ['example', 'moved']);
echo "\nMoved example/app1/temp to example/moved\n";
echo "  New path: " . json_encode($movedDir->getPath()) . "\n";

// Verify data is still accessible
$movedData = $db->get($movedDir->pack(['data']));
echo "  Data after move: '{$movedData}'\n";

// Verify old location no longer exists
$oldExists = $directoryLayer->exists($db, ['example', 'app1', 'temp']);
echo "  Old location exists: " . ($oldExists ? 'true' : 'false') . "\n\n";

// Removing directories
echo "--- Removing Directories ---\n";

// Remove a single directory
$directoryLayer->remove($db, ['example', 'moved']);
echo "Removed directory: example/moved\n";

$existsAfterRemove = $directoryLayer->exists($db, ['example', 'moved']);
echo "  Exists after removal: " . ($existsAfterRemove ? 'true' : 'false') . "\n\n";

// Remove with subdirectories (recursive)
echo "Removing example/app1 (with subdirectories):\n";
$directoryLayer->remove($db, ['example', 'app1']);
echo "  Removed example/app1\n";

$app1Exists = $directoryLayer->exists($db, ['example', 'app1']);
echo "  Exists after removal: " . ($app1Exists ? 'true' : 'false') . "\n\n";

// RemoveIfExists (doesn't throw if directory doesn't exist)
echo "--- RemoveIfExists ---\n";
$result1 = $directoryLayer->removeIfExists($db, ['example', 'app2']);
echo "removeIfExists(['example', 'app2']): " . ($result1 ? 'true' : 'false') . "\n";

$result2 = $directoryLayer->removeIfExists($db, ['example', 'nonexistent']);
echo "removeIfExists(['example', 'nonexistent']): " . ($result2 ? 'true' : 'false') . " (no error)\n\n";

// Cleanup remaining
echo "--- Final Cleanup ---\n";
if ($directoryLayer->exists($db, ['example'])) {
    $directoryLayer->remove($db, ['example']);
    echo "Removed example directory and all contents\n";
}

echo "\n=== Example Complete ===\n";
