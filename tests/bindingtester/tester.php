#!/usr/bin/env php
<?php

/**
 * FoundationDB binding tester driver for the crazy-goat/fdb-php binding.
 *
 * Implements the upstream stack machine protocol from
 * bindings/bindingtester/spec/bindingApiTester.md (plus the directory
 * layer extension). It is driven by the upstream Python harness:
 *
 *   python3 bindingtester.py --test-name tuple --api-version 730 \
 *       "php tests/bindingtester/tester.php" [options]
 *
 * Usage: php tester.php <prefix> <api_version> [cluster_file]
 *
 * See docs/bindingtester.md for how to run the suites locally.
 */

declare(strict_types=1);

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Tests\BindingTester\StackMachine;

require __DIR__ . '/../../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tester.php must be run from the CLI\n");
    exit(1);
}

if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php tester.php <prefix> <api_version> [cluster_file]\n");
    exit(1);
}

$prefix = (string) $argv[1];
$apiVersion = (int) $argv[2];
$clusterFile = $argv[3] ?? null;

try {
    FoundationDB::apiVersion($apiVersion);
    $db = FoundationDB::open($clusterFile);

    $machine = new StackMachine($db, $prefix);
    $machine->run();

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'binding tester failed: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
}
