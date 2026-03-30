<?php

/**
 * Error Handling Example
 *
 * This script demonstrates FoundationDB error handling:
 * - Try/catch with FDBException
 * - Error predicates (isRetryable, etc.)
 * - Show that transact() handles retries automatically
 * - DirectoryException example
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\Directory\DirectoryException;
use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Transaction;

echo "=== FoundationDB Error Handling ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

// Basic FDBException handling
echo "--- Basic FDBException Handling ---\n";
echo "FDBException is thrown for FoundationDB errors.\n";
echo "It contains the error code and message.\n\n";

// Example: Try to get a key with an invalid selector (demonstrates error handling)
try {
    // This would normally work, but let's show the pattern
    $value = $db->get('some_key');
    echo "Successfully retrieved value: " . ($value === null ? 'null' : "'{$value}'") . "\n";
} catch (FDBException $e) {
    echo "FDBException caught!\n";
    echo "  Error code: {$e->fdbCode}\n";
    echo "  Message: {$e->getMessage()}\n";
}
echo "\n";

// Error predicates
echo "--- Error Predicates ---\n";
echo "FDBException provides methods to check error characteristics:\n\n";

// Simulate checking error predicates
// In real scenarios, these would be checked in catch blocks
echo "Error predicate methods:\n";
echo "  - isRetryable(): Returns true if the error is retryable\n";
echo "  - isMaybeCommitted(): Returns true if transaction may have committed\n";
echo "  - isRetryableNotCommitted(): Returns true if retryable and not committed\n\n";

echo "Example error codes and their predicates:\n";
$errorCodes = [
    1007 => 'Transaction timed out',
    1009 => 'Transaction too old',
    1020 => 'Not committed (conflict)',
    1031 => 'Transaction cancelled',
    2101 => 'Operation cancelled',
];

foreach ($errorCodes as $code => $description) {
    $isRetryable = FDBException::testPredicate(
        \CrazyGoat\FoundationDB\Enum\ErrorPredicate::Retryable,
        $code,
    );
    $isMaybeCommitted = FDBException::testPredicate(
        \CrazyGoat\FoundationDB\Enum\ErrorPredicate::MaybeCommitted,
        $code,
    );
    $isRetryableNotCommitted = FDBException::testPredicate(
        \CrazyGoat\FoundationDB\Enum\ErrorPredicate::RetryableNotCommitted,
        $code,
    );

    echo "  Code {$code} ({$description}):\n";
    echo "    isRetryable: " . ($isRetryable ? 'true' : 'false') . "\n";
    echo "    isMaybeCommitted: " . ($isMaybeCommitted ? 'true' : 'false') . "\n";
    echo "    isRetryableNotCommitted: " . ($isRetryableNotCommitted ? 'true' : 'false') . "\n";
}
echo "\n";

// Automatic retry in transact()
echo "--- Automatic Retry in transact() ---\n";
echo "The transact() method automatically handles retryable errors:\n\n";

echo "How it works:\n";
echo "  1. Execute the transaction callback\n";
echo "  2. If FDBException with retryable error is thrown:\n";
echo "     a. Call onError() to determine retry behavior\n";
echo "     b. Wait for the recommended delay\n";
echo "     c. Create new transaction and retry\n";
echo "  3. Repeat until success or non-retryable error\n\n";

// Demonstrate successful transaction
$retryCount = 0;
$result = $db->transact(function (Transaction $tr) use (&$retryCount): string {
    ++$retryCount;
    echo "  Transaction attempt #{$retryCount}\n";

    // Normal operations
    $tr->set('example/error_handling/test', 'value' . $retryCount);

    return "Success after {$retryCount} attempt(s)";
});

echo "\nResult: {$result}\n";
echo "Note: In this demo, no retries were needed.\n";
echo "      In production, retries happen automatically on conflicts.\n\n";

// Manual error handling
echo "--- Manual Error Handling ---\n";
echo "For custom retry logic or non-transact operations:\n\n";

$maxRetries = 3;
$attempt = 0;
$success = false;

while ($attempt < $maxRetries && !$success) {
    ++$attempt;
    $tr = $db->createTransaction();

    try {
        echo "  Manual attempt #{$attempt}...\n";

        // Do work
        $tr->set('example/error_handling/manual', "attempt_{$attempt}");

        // Commit
        $tr->commit()->await();
        $success = true;
        echo "  Commit successful!\n";
    } catch (FDBException $e) {
        echo "  Error: {$e->getMessage()} (code: {$e->fdbCode})\n";

        if ($e->isRetryable()) {
            echo "  Error is retryable, waiting...\n";
            $tr->onError($e->fdbCode)->await();
            echo "  Ready to retry.\n";
        } else {
            echo "  Error is not retryable, giving up.\n";
            break;
        }
    }
}

echo "\nManual transaction " . ($success ? 'succeeded' : 'failed') . "\n\n";

// DirectoryException
echo "--- DirectoryException ---\n";
echo "Directory operations throw DirectoryException for directory-specific errors.\n\n";

$directoryLayer = new DirectoryLayer();

// Example 1: Try to create a directory that already exists
echo "Example: Creating a directory that already exists\n";
try {
    // First, create the directory
    $directoryLayer->create($db, ['example', 'test_dir']);
    echo "  Created directory: example/test_dir\n";

    // Try to create it again (should fail)
    $directoryLayer->create($db, ['example', 'test_dir']);
    echo "  Unexpectedly succeeded in creating duplicate\n";
} catch (DirectoryException $e) {
    echo "  DirectoryException caught: {$e->getMessage()}\n";
}

// Example 2: Try to open a non-existent directory
echo "\nExample: Opening a non-existent directory\n";
try {
    $directoryLayer->open($db, ['example', 'nonexistent', 'dir']);
    echo "  Unexpectedly succeeded\n";
} catch (DirectoryException $e) {
    echo "  DirectoryException caught: {$e->getMessage()}\n";
}

// Example 3: Try to move to an existing directory
echo "\nExample: Moving to an existing directory\n";
try {
    // Create source and destination
    $directoryLayer->create($db, ['example', 'source']);
    $directoryLayer->create($db, ['example', 'dest']);
    echo "  Created source and dest directories\n";

    // Try to move source to dest (should fail)
    $directoryLayer->move($db, ['example', 'source'], ['example', 'dest']);
    echo "  Unexpectedly succeeded\n";
} catch (DirectoryException $e) {
    echo "  DirectoryException caught: {$e->getMessage()}\n";
}

// Cleanup directories
try {
    if ($directoryLayer->exists($db, ['example', 'test_dir'])) {
        $directoryLayer->remove($db, ['example', 'test_dir']);
    }
    if ($directoryLayer->exists($db, ['example', 'source'])) {
        $directoryLayer->remove($db, ['example', 'source']);
    }
    if ($directoryLayer->exists($db, ['example', 'dest'])) {
        $directoryLayer->remove($db, ['example', 'dest']);
    }
    if ($directoryLayer->exists($db, ['example'])) {
        $directoryLayer->remove($db, ['example']);
    }
} catch (\Exception $e) {
    // Ignore cleanup errors
}
echo "\n";

// Best practices
echo "--- Error Handling Best Practices ---\n";
echo "1. Use transact() for automatic retry handling\n";
echo "2. Catch FDBException for database errors\n";
echo "3. Check error predicates for retry decisions\n";
echo "4. Use DirectoryException for directory operations\n";
echo "5. Always clean up resources (transactions, watches)\n";
echo "6. Log errors with their codes for debugging\n";
echo "7. Set appropriate timeouts for your use case\n";
echo "8. Handle 'maybe committed' cases carefully\n\n";

// Cleanup
echo "--- Cleanup ---\n";
$db->clearRangeStartsWith('example/error_handling/');
echo "Test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
