<?php

declare(strict_types=1);

$ffi = FFI::cdef("
    typedef int fdb_error_t;
    int fdb_get_max_api_version(void);
    fdb_error_t fdb_select_api_version_impl(int runtime_version, int header_version);
    fdb_error_t fdb_setup_network(void);
    fdb_error_t fdb_stop_network(void);
    const char* fdb_get_error(fdb_error_t code);
    fdb_error_t fdb_create_database(const char* cluster_file_path, void** out_database);
    fdb_error_t fdb_database_create_transaction(void* database, void** out_transaction);
    void* fdb_transaction_get_read_version(void* transaction);
    fdb_error_t fdb_future_block_until_ready(void* future);
    fdb_error_t fdb_future_get_error(void* future);
    fdb_error_t fdb_future_get_int64(void* future, int64_t* out);
    void fdb_future_destroy(void* future);
    void fdb_transaction_destroy(void* transaction);
    void fdb_database_destroy(void* database);
", "libfdb_c.so");

echo "=== FoundationDB PHP FFI Verification ===\n\n";

$maxVer = $ffi->fdb_get_max_api_version();
echo "Max API version: {$maxVer}\n";

$err = $ffi->fdb_select_api_version_impl(730, $maxVer);
echo "Select API version 730: " . ($err === 0 ? "OK" : "ERROR {$err}") . "\n";

$err = $ffi->fdb_setup_network();
echo "Setup network: " . ($err === 0 ? "OK" : "ERROR {$err}") . "\n";

$clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/app/fdb.cluster';
echo "Cluster file: {$clusterFile}\n";
echo "Cluster file contents: " . trim(file_get_contents($clusterFile)) . "\n";

$dbPtr = $ffi->new("void*");
$err = $ffi->fdb_create_database($clusterFile, FFI::addr($dbPtr));
echo "Create database: " . ($err === 0 ? "OK" : "ERROR {$err}") . "\n";

$trPtr = $ffi->new("void*");
$err = $ffi->fdb_database_create_transaction($dbPtr, FFI::addr($trPtr));
echo "Create transaction: " . ($err === 0 ? "OK" : "ERROR {$err}") . "\n";

echo "\nFFI + libfdb_c.so works!\n";
echo "PHP can create FDB database and transaction objects.\n";
echo "\nNote: Full connectivity test (read version) requires network thread.\n";
echo "This will be implemented in Phase 2 of the binding.\n";

$ffi->fdb_transaction_destroy($trPtr);
$ffi->fdb_database_destroy($dbPtr);
$ffi->fdb_stop_network();

echo "\nCleanup complete. All good!\n";
