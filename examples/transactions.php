<?php

/**
 * Transaction Operations Example
 *
 * This script demonstrates FoundationDB transactional operations:
 * - Using transact() for read-write transactions with automatic retry
 * - Reading within a transaction using get()->await()
 * - Using readTransact() for read-only operations
 * - Snapshot reads for reduced conflict rates
 * - Returning values from transactions
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Transaction;

echo "=== FoundationDB Transaction Operations ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

$prefix = 'example/txn/';

// Cleanup
echo "Cleaning up existing test data...\n";
$db->clearRangeStartsWith($prefix);
echo "Done.\n\n";

// Set up initial data
echo "--- Setting Up Initial Data ---\n";
$db->set($prefix . 'account1', '1000');
$db->set($prefix . 'account2', '500');
echo "Created two accounts with balances\n\n";

// Basic transact() with multiple operations
echo "--- Basic transact() Example ---\n";
$result = $db->transact(function (Transaction $tr): string {
    // Read both account balances
    $balance1 = $tr->get('example/txn/account1')->await();
    $balance2 = $tr->get('example/txn/account2')->await();

    echo "  Inside transaction - Account 1: {$balance1}, Account 2: {$balance2}\n";

    // Perform transfer: move 200 from account1 to account2
    $newBalance1 = (int) $balance1 - 200;
    $newBalance2 = (int) $balance2 + 200;

    $tr->set('example/txn/account1', (string) $newBalance1);
    $tr->set('example/txn/account2', (string) $newBalance2);

    echo "  Inside transaction - Transferred 200 from account1 to account2\n";

    return "Transfer complete: account1={$newBalance1}, account2={$newBalance2}";
});

echo "Transaction result: {$result}\n";

// Verify the transfer
$final1 = $db->get($prefix . 'account1');
$final2 = $db->get($prefix . 'account2');
echo "Final balances - Account 1: {$final1}, Account 2: {$final2}\n\n";

// readTransact() for read-only operations
echo "--- readTransact() for Read-Only Operations ---\n";
$totalBalance = $db->readTransact(function (Transaction $tr): int {
    // Snapshot reads don't add read conflict ranges
    // This is more efficient for pure read operations
    $balance1 = $tr->get('example/txn/account1')->await();
    $balance2 = $tr->get('example/txn/account2')->await();

    return (int) $balance1 + (int) $balance2;
});
echo "Total balance (read-only transaction): {$totalBalance}\n\n";

// Snapshot reads within a transaction
echo "--- Snapshot Reads Within Transaction ---\n";
$db->transact(function (Transaction $tr): void {
    // Regular read - adds conflict range
    $regularRead = $tr->get('example/txn/account1')->await();
    echo "  Regular read: {$regularRead}\n";

    // Snapshot read - does not add conflict range
    // Useful for reading auxiliary data that shouldn't cause conflicts
    $snapshotRead = $tr->snapshot()->get('example/txn/account1')->await();
    echo "  Snapshot read: {$snapshotRead}\n";

    // Snapshot reads are useful for:
    // - Reading configuration or metadata
    // - Getting approximate counts
    // - Any read where consistency with writes is not critical
});
echo "Snapshot reads completed\n\n";

// Returning complex values from transactions
echo "--- Returning Values from Transactions ---\n";
$accountData = $db->transact(function (Transaction $tr): array {
    $data = [];

    // Get all accounts and their balances
    $range = $tr->getRangeStartsWith('example/txn/account');
    foreach ($range as $kv) {
        $accountName = basename($kv->key);
        $data[$accountName] = [
            'balance' => (int) $kv->value,
            'key' => $kv->key,
        ];
    }

    return $data;
});

echo "Account data returned from transaction:\n";
foreach ($accountData as $name => $info) {
    echo "  - {$name}: balance={$info['balance']}, key={$info['key']}\n";
}

// Demonstrating automatic retry behavior
echo "\n--- Automatic Retry Behavior ---\n";
echo "The transact() method automatically handles retryable errors.\n";
echo "If a transaction conflicts, it will be retried with exponential backoff.\n";
echo "The callable is re-executed with a fresh transaction context.\n\n";

// Transaction with conditional logic
echo "--- Conditional Transaction Logic ---\n";
$withdrawalResult = $db->transact(function (Transaction $tr): array {
    $balance = (int) $tr->get('example/txn/account1')->await();
    $withdrawalAmount = 300;

    if ($balance < $withdrawalAmount) {
        return [
            'success' => false,
            'message' => "Insufficient funds: {$balance} < {$withdrawalAmount}",
        ];
    }

    $newBalance = $balance - $withdrawalAmount;
    $tr->set('example/txn/account1', (string) $newBalance);

    return [
        'success' => true,
        'message' => "Withdrew {$withdrawalAmount}, new balance: {$newBalance}",
        'newBalance' => $newBalance,
    ];
});

echo "Withdrawal result: {$withdrawalResult['message']}\n";

// Cleanup
echo "\n--- Cleanup ---\n";
$db->clearRangeStartsWith($prefix);
echo "All test data cleaned up.\n";

echo "\n=== Example Complete ===\n";
