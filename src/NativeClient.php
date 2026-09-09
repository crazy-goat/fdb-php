<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI;
use FFI\CData;

final class NativeClient
{
    private const FDB_HEADER = '
        typedef int fdb_error_t;
        typedef int fdb_bool_t;
        typedef struct FDB_future FDBFuture;
        typedef struct FDB_database FDBDatabase;
        typedef struct FDB_tenant FDBTenant;
        typedef struct FDB_transaction FDBTransaction;
        typedef struct FDB_result FDBResult;

        typedef struct __attribute__((packed)) {
            const char* key;
            int key_length;
            const char* value;
            int value_length;
        } FDBKeyValue;

        typedef struct __attribute__((packed)) {
            const char* key;
            int key_length;
        } FDBKey;

        /* Memory layout of keyrange (packed via #pragma pack(4) in fdb_c.h). */
        typedef struct __attribute__((packed)) {
            const char* begin_key;
            int begin_key_length;
            const char* end_key;
            int end_key_length;
        } FDBKeyRange;

        /* Memory layout of granulesummary (packed via #pragma pack(4) in fdb_c.h). */
        typedef struct __attribute__((packed)) {
            const char* begin_key;
            int begin_key_length;
            const char* end_key;
            int end_key_length;
            int64_t snapshot_version;
            int64_t snapshot_size;
            int64_t delta_version;
            int64_t delta_size;
        } FDBGranuleSummary;

        typedef int64_t (*FDBBGStartLoadFn)(
            const char* filename, int filename_length,
            int64_t offset, int64_t length, int64_t full_file_length, void* context
        );
        typedef uint8_t* (*FDBBGGetLoadFn)(int64_t load_id, void* context);
        typedef void (*FDBBGFreeLoadFn)(int64_t load_id, void* context);

        /* FDBReadBlobGranuleContext is not packed in fdb_c.h. */
        typedef struct {
            void* user_context;
            FDBBGStartLoadFn start_load_f;
            FDBBGGetLoadFn get_load_f;
            FDBBGFreeLoadFn free_load_f;
            fdb_bool_t debug_no_materialize;
            int granule_parallelism;
        } FDBReadBlobGranuleContext;

        /* Memory layout of KeySelectorRef (packed via #pragma pack(4) in fdb_c.h). */
        typedef struct __attribute__((packed)) {
            FDBKey key;
            fdb_bool_t orEqual;
            int offset;
        } FDBKeySelector;

        /* Memory layout of GetRangeReqAndResultRef (packed via #pragma pack(4) in fdb_c.h). */
        typedef struct __attribute__((packed)) {
            FDBKeySelector begin;
            FDBKeySelector end;
            FDBKeyValue* data;
            int m_size;
            int m_capacity;
        } FDBGetRangeReqAndResult;

        /* Memory layout of MappedKeyValueRef (packed via #pragma pack(4) in fdb_c.h). */
        /* Memory layout of MappedKeyValueRef (not packed in fdb_c.h).
         *
         * The public fdb_c.h FDBMappedKeyValue only models the getRange
         * alternative of the underlying std::variant, which is not what the
         * native client actually produces: the reply carries either a point
         * lookup (GetValueReqAndResultRef, variant index 0) or a range
         * lookup (GetRangeReqAndResultRef, variant index 1). The real C++
         * object is laid out as: index key (12B), index value (12B), an
         * 80-byte variant union at offset 24, and a 4-byte variant index at
         * offset 104 (padded to a 112-byte stride). FDBGetValue /
         * FDBGetRangeReqAndResultFull below mirror both alternatives over
         * the 80-byte union, so the struct declared here mirrors the real
         * memory layout rather than the one from fdb_c.h.
         */
        typedef struct __attribute__((packed)) {
            FDBKey key;
            FDBKey value;
            bool present;
            unsigned char tail[55];
        } FDBGetValueReqAndResult;

        typedef struct __attribute__((packed)) {
            FDBGetRangeReqAndResult reqAndResult;
            unsigned char tail[24];
        } FDBGetRangeReqAndResultFull;

        typedef union __attribute__((packed)) {
            FDBGetValueReqAndResult getValue;
            FDBGetRangeReqAndResultFull getRange;
        } FDBMappedReqAndResult;

        typedef struct __attribute__((packed)) {
            FDBKey key;
            FDBKey value;
            FDBMappedReqAndResult reqAndResult;
            int variant_index;
            unsigned char tail[4];
        } FDBMappedKeyValue;

        fdb_error_t fdb_select_api_version_impl(int runtime_version, int header_version);
        int fdb_get_max_api_version();
        const char* fdb_get_error(fdb_error_t code);
        fdb_bool_t fdb_error_predicate(int predicate_test, fdb_error_t code);

        fdb_error_t fdb_network_set_option(int option, const void* value, int value_length);
        fdb_error_t fdb_setup_network();
        fdb_error_t fdb_run_network();
        fdb_error_t fdb_stop_network();
        const char* fdb_get_client_version();

        void fdb_future_destroy(FDBFuture* f);
        void fdb_future_release_memory(FDBFuture* f);
        void fdb_future_cancel(FDBFuture* f);
        fdb_error_t fdb_future_block_until_ready(FDBFuture* f);
        fdb_bool_t fdb_future_is_ready(FDBFuture* f);
        fdb_error_t fdb_future_get_error(FDBFuture* f);
        fdb_error_t fdb_future_get_int64(FDBFuture* f, int64_t* out);
        fdb_error_t fdb_future_get_uint64(FDBFuture* f, uint64_t* out);
        fdb_error_t fdb_future_get_double(FDBFuture* f, double* out);
        fdb_error_t fdb_future_get_bool(FDBFuture* f, fdb_bool_t* out);
        fdb_error_t fdb_future_get_key(FDBFuture* f, const char** out_key, int* out_key_length);
        fdb_error_t fdb_future_get_value(
            FDBFuture* f, fdb_bool_t* out_present, const char** out_value, int* out_value_length
        );
        fdb_error_t fdb_future_get_keyvalue_array(
            FDBFuture* f, const FDBKeyValue** out_kv, int* out_count, fdb_bool_t* out_more
        );
        fdb_error_t fdb_future_get_key_array(FDBFuture* f, const FDBKey** out_keys, int* out_count);
        fdb_error_t fdb_future_get_string_array(FDBFuture* f, const char*** out_strings, int* out_count);
        fdb_error_t fdb_future_get_keyrange_array(FDBFuture* f, const FDBKeyRange** out_ranges, int* out_count);
        fdb_error_t fdb_future_get_granule_summary_array(
            FDBFuture* f, const FDBGranuleSummary** out_summaries, int* out_count
        );

        void fdb_result_destroy(FDBResult* r);
        fdb_error_t fdb_result_get_keyvalue_array(
            FDBResult* r, const FDBKeyValue** out_kv, int* out_count, fdb_bool_t* out_more
        );

        fdb_error_t fdb_create_database(const char* cluster_file_path, FDBDatabase** out_database);
        fdb_error_t fdb_create_database_from_connection_string(
            const char* connection_string, FDBDatabase** out_database
        );
        void fdb_database_destroy(FDBDatabase* d);
        double fdb_database_get_main_thread_busyness(FDBDatabase* d);
        FDBFuture* fdb_database_get_client_status(FDBDatabase* d);
        FDBFuture* fdb_database_get_server_protocol(FDBDatabase* d, uint64_t expected_version);
        fdb_error_t fdb_database_set_option(FDBDatabase* d, int option, const void* value, int value_length);
        fdb_error_t fdb_database_create_transaction(FDBDatabase* d, FDBTransaction** out_transaction);
        FDBFuture* fdb_database_create_snapshot(
            FDBDatabase* d, const char* uid, int uid_length,
            const char* snap_command, int snap_command_length
        );
        FDBFuture* fdb_database_force_recovery_with_data_loss(
            FDBDatabase* d, const char* dcid, int dcid_length
        );
        FDBFuture* fdb_database_reboot_worker(
            FDBDatabase* d, const char* address, int address_length, fdb_bool_t check, int duration
        );
        fdb_error_t fdb_database_open_tenant(
            FDBDatabase* d, const char* tenant_name, int tenant_name_length, FDBTenant** out_tenant
        );
        FDBFuture* fdb_database_blobbify_range(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_database_blobbify_range_blocking(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_database_unblobbify_range(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_database_list_blobbified_ranges(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, int range_limit
        );
        FDBFuture* fdb_database_verify_blob_range(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, int64_t version
        );
        FDBFuture* fdb_database_flush_blob_range(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, fdb_bool_t compact, int64_t version
        );
        FDBFuture* fdb_database_purge_blob_granules(
            FDBDatabase* d, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, int64_t purge_version, fdb_bool_t force
        );
        FDBFuture* fdb_database_wait_purge_granules_complete(
            FDBDatabase* d, const char* purge_key_name, int purge_key_name_length
        );

        void fdb_tenant_destroy(FDBTenant* t);
        fdb_error_t fdb_tenant_create_transaction(FDBTenant* t, FDBTransaction** out_transaction);
        FDBFuture* fdb_tenant_get_id(FDBTenant* tenant);
        FDBFuture* fdb_tenant_blobbify_range(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_tenant_blobbify_range_blocking(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_tenant_unblobbify_range(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_tenant_list_blobbified_ranges(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, int range_limit
        );
        FDBFuture* fdb_tenant_verify_blob_range(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, int64_t version
        );
        FDBFuture* fdb_tenant_flush_blob_range(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, fdb_bool_t compact, int64_t version
        );
        FDBFuture* fdb_tenant_purge_blob_granules(
            FDBTenant* t, const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length, int64_t purge_version, fdb_bool_t force
        );
        FDBFuture* fdb_tenant_wait_purge_granules_complete(
            FDBTenant* t, const char* purge_key_name, int purge_key_name_length
        );

        void fdb_transaction_destroy(FDBTransaction* tr);
        void fdb_transaction_cancel(FDBTransaction* tr);
        fdb_error_t fdb_transaction_set_option(
            FDBTransaction* tr, int option, const void* value, int value_length
        );
        void fdb_transaction_set_read_version(FDBTransaction* tr, int64_t version);
        FDBFuture* fdb_transaction_get_read_version(FDBTransaction* tr);
        FDBFuture* fdb_transaction_get(
            FDBTransaction* tr, const char* key_name, int key_name_length, fdb_bool_t snapshot
        );
        FDBFuture* fdb_transaction_get_key(
            FDBTransaction* tr,
            const char* key_name, int key_name_length,
            fdb_bool_t or_equal, int offset, fdb_bool_t snapshot
        );
        FDBFuture* fdb_transaction_get_range(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            fdb_bool_t begin_or_equal, int begin_offset,
            const char* end_key_name, int end_key_name_length,
            fdb_bool_t end_or_equal, int end_offset,
            int limit, int target_bytes, int streaming_mode, int iteration,
            fdb_bool_t snapshot, fdb_bool_t reverse
        );
        FDBFuture* fdb_transaction_get_mapped_range(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            fdb_bool_t begin_or_equal, int begin_offset,
            const char* end_key_name, int end_key_name_length,
            fdb_bool_t end_or_equal, int end_offset,
            const char* mapper_name, int mapper_name_length,
            int limit, int target_bytes, int streaming_mode, int iteration,
            fdb_bool_t snapshot, fdb_bool_t reverse
        );
        fdb_error_t fdb_future_get_mappedkeyvalue_array(
            FDBFuture* f, const FDBMappedKeyValue** out_kvm, int* out_count, fdb_bool_t* out_more
        );
        FDBFuture* fdb_transaction_get_estimated_range_size_bytes(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        FDBFuture* fdb_transaction_get_range_split_points(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length,
            int64_t chunk_size
        );
        FDBFuture* fdb_transaction_get_addresses_for_key(
            FDBTransaction* tr, const char* key_name, int key_name_length
        );
        FDBFuture* fdb_transaction_get_blob_granule_ranges(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length,
            int range_limit
        );
        FDBResult* fdb_transaction_read_blob_granules(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length,
            int64_t begin_version, int64_t read_version,
            FDBReadBlobGranuleContext granule_context
        );
        FDBFuture* fdb_transaction_summarize_blob_granules(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length,
            int64_t summary_version, int range_limit
        );
        void fdb_transaction_set(
            FDBTransaction* tr,
            const char* key_name, int key_name_length,
            const char* value, int value_length
        );
        void fdb_transaction_clear(FDBTransaction* tr, const char* key_name, int key_name_length);
        void fdb_transaction_clear_range(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length
        );
        void fdb_transaction_atomic_op(
            FDBTransaction* tr,
            const char* key_name, int key_name_length,
            const char* param, int param_length,
            int operation_type
        );
        FDBFuture* fdb_transaction_commit(FDBTransaction* tr);
        fdb_error_t fdb_transaction_get_committed_version(FDBTransaction* tr, int64_t* version);
        FDBFuture* fdb_transaction_get_approximate_size(FDBTransaction* tr);
        FDBFuture* fdb_transaction_get_total_cost(FDBTransaction* tr);
        FDBFuture* fdb_transaction_get_tag_throttled_duration(FDBTransaction* tr);
        FDBFuture* fdb_transaction_get_versionstamp(FDBTransaction* tr);
        FDBFuture* fdb_transaction_watch(FDBTransaction* tr, const char* key_name, int key_name_length);
        FDBFuture* fdb_transaction_on_error(FDBTransaction* tr, fdb_error_t error);
        void fdb_transaction_reset(FDBTransaction* tr);
        fdb_error_t fdb_transaction_add_conflict_range(
            FDBTransaction* tr,
            const char* begin_key_name, int begin_key_name_length,
            const char* end_key_name, int end_key_name_length,
            int type
        );
    ';

    private const PTHREAD_HEADER = '
        typedef unsigned long pthread_t;
        typedef void* (*thread_func)(void*);
        int pthread_create(pthread_t* thread, const void* attr, thread_func start_routine, void* arg);
        int pthread_join(pthread_t thread, void** retval);
    ';

    private const LIBDL_HEADER = '
        void* dlopen(const char* filename, int flags);
        void* dlsym(void* handle, const char* symbol);
        int dlclose(void* handle);
        char* dlerror();
    ';

    private const RTLD_LAZY = 1;

    private static ?self $instance = null;

    /**
     * Environment variable that can pin the absolute path of the FoundationDB
     * client library loaded through FFI. When set, it takes precedence over
     * the bare soname ("libfdb_c.so"), which is resolved through the dynamic
     * linker search path and is therefore subject to library hijacking
     * (see issue #49).
     */
    public const LIBRARY_PATH_ENV = 'FDB_LIBRARY_PATH';

    /** Bare soname used when no explicit path is configured. */
    private const DEFAULT_LIBRARY = 'libfdb_c.so';

    public readonly FFI $fdb;

    /**
     * Resolved library path/soname used for both FFI::cdef() and dlopen().
     * Either an absolute path (pinned, recommended in production) or the
     * bare soname.
     */
    private readonly string $fdbLibraryPath;

    private readonly FFI $pthread;

    private readonly FFI $libdl;

    private bool $networkStarted = false;

    /**
     * True once fdb_setup_network() has succeeded, even if the network thread
     * could not be created. Tracked separately from $networkStarted so that a
     * partial initialization is never mistaken for a "not set up" state (which
     * would cause a second fdb_setup_network() call and an unjoinable network
     * thread at shutdown). reset/rolled back in rollbackNetworkSetup() and
     * stopNetwork().
     */
    private bool $networkSetup = false;

    private ?CData $networkThread = null;

    /** @var \FFI\CData|null Handle returned by dlopen() of the FDB library, closed in stopNetwork(). */
    private ?CData $fdbLibraryHandle = null;

    /**
     * Callables registered via `FoundationDB::onNetworkThreadCompletion()`,
     * invoked once from stopNetwork() after the FDB network thread has been
     * joined. Deliberately NOT registered through the native
     * `fdb_add_network_thread_completion_hook()` API: native completion
     * hooks run on the FDB network thread, where executing PHP is unsafe
     * (the Zend engine is not re-entrant). Running them on the main thread
     * after pthread_join() preserves the ordering guarantee (the network
     * thread — and therefore all native hooks — has already finished), so
     * they are safe places to flush traces/metrics at shutdown.
     *
     * @var list<callable(): void>
     */
    private array $networkCompletionHooks = [];

    private function __construct(?string $fdbLibraryPath = null)
    {
        $this->fdbLibraryPath = self::resolveLibraryPath($fdbLibraryPath);
        $this->fdb = FFI::cdef(self::FDB_HEADER, $this->fdbLibraryPath);
        $this->pthread = FFI::cdef(self::PTHREAD_HEADER, 'libpthread.so.0');
        $this->libdl = FFI::cdef(self::LIBDL_HEADER, 'libdl.so.2');
    }

    /**
     * Resolves the library to load for FFI::cdef()/dlopen().
     *
     * Precedence: explicit argument > FDB_LIBRARY_PATH environment variable
     * > the bare soname ("libfdb_c.so"). An explicitly configured value must
     * be an absolute path: loading by relative path would still traverse
     * attacker-influenced directories, defeating the purpose of pinning.
     *
     * @throws \InvalidArgumentException when a configured path is not absolute
     */
    public static function resolveLibraryPath(?string $configured = null): string
    {
        $path = $configured ?? getenv(self::LIBRARY_PATH_ENV);

        if ($path === false || $path === '') {
            return self::DEFAULT_LIBRARY;
        }

        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Configured %s must be an absolute path to libfdb_c, got: "%s"',
                self::LIBRARY_PATH_ENV,
                $path,
            ));
        }

        return $path;
    }

    /** The resolved library path/soname this client was loaded from. */
    public function getLibraryPath(): string
    {
        return $this->fdbLibraryPath;
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** @internal */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function checkError(int $code): void
    {
        if ($code !== 0) {
            throw new FDBException($code);
        }
    }

    public function getErrorMessage(int $code): string
    {
        /** @var string $message */
        $message = $this->fdb->fdb_get_error($code);

        return $message;
    }

    public function ensureNetwork(): void
    {
        if ($this->networkStarted) {
            return;
        }

        try {
            if (!$this->networkSetup) {
                $this->checkError($this->fdb->fdb_setup_network());
                $this->networkSetup = true;
            }

            $this->networkThread = $this->pthread->new('pthread_t');

            $fdbHandle = $this->libdl->dlopen($this->fdbLibraryPath, self::RTLD_LAZY);
            if ($fdbHandle === null || FFI::isNull($fdbHandle)) {
                throw new \RuntimeException(
                    sprintf('Failed to dlopen %s: ', $this->fdbLibraryPath) . $this->lastDlError(),
                );
            }
            $this->fdbLibraryHandle = $fdbHandle;

            $runNetworkPtr = $this->libdl->dlsym($fdbHandle, 'fdb_run_network');
            if ($runNetworkPtr === null || FFI::isNull($runNetworkPtr)) {
                throw new \RuntimeException(
                    'Failed to dlsym fdb_run_network: ' . $this->lastDlError(),
                );
            }

            $funcPtr = FFI::cast($this->pthread->type('thread_func'), $runNetworkPtr);

            $result = $this->pthread->pthread_create(
                FFI::addr($this->networkThread),
                null,
                $funcPtr,
                null,
            );

            if ($result !== 0) {
                throw new \RuntimeException('Failed to create network thread: pthread_create returned ' . $result);
            }
        } catch (\Throwable $e) {
            // fdb_setup_network() succeeded but a later step failed: roll the
            // network back down (fdb_stop_network() is the documented cleanup
            // when the network thread could not be created), release the
            // dlopen'd handle and clear the setup flag, so the client is left
            // in a consistent "not started" state that can be safely retried
            // and whose shutdown path does not wedge.
            $this->rollbackNetworkSetup();

            throw $e;
        }

        $this->networkStarted = true;

        register_shutdown_function($this->stopNetwork(...));
    }

    /**
     * dlerror() returns NULL when no error occurred since the last call, so
     * the message must never be passed to FFI::string() unchecked.
     */
    private function lastDlError(): string
    {
        $error = $this->libdl->dlerror();

        if ($error === null || FFI::isNull($error)) {
            return 'unknown dl error';
        }

        return FFI::string($error);
    }

    /**
     * Reverts a partially initialized network (setup done, thread not running)
     * back to a clean "never started" state. No-op when the network was never
     * set up or is already fully started.
     */
    private function rollbackNetworkSetup(): void
    {
        if ($this->networkStarted || !$this->networkSetup) {
            return;
        }

        $this->fdb->fdb_stop_network();
        $this->networkSetup = false;

        if ($this->fdbLibraryHandle instanceof \FFI\CData && !FFI::isNull($this->fdbLibraryHandle)) {
            $this->libdl->dlclose($this->fdbLibraryHandle);
            $this->fdbLibraryHandle = null;
        }

        $this->networkThread = null;
    }

    /**
     * Register a callable to be invoked once when the FDB network thread
     * stops, i.e. from stopNetwork() after the network thread has been
     * joined. Useful for flushing traces/metrics at shutdown.
     *
     * NOTE: the callable is executed on the PHP main thread, not on the FDB
     * network thread. The native `fdb_add_network_thread_completion_hook()`
     * API is intentionally not used for PHP callables: its hook runs on the
     * network thread, where executing PHP is unsafe. The deferred invocation
     * in stopNetwork() happens strictly after the network thread (and any
     * native hooks) has finished, so the ordering guarantee users rely on
     * is preserved.
     */
    public function onNetworkThreadCompletion(callable $hook): void
    {
        $this->networkCompletionHooks[] = $hook;
    }

    /**
     * @internal
     *
     * @return list<callable(): void>
     */
    public function getNetworkCompletionHooks(): array
    {
        return $this->networkCompletionHooks;
    }

    public function stopNetwork(): void
    {
        if (!$this->networkStarted) {
            return;
        }

        // Close all tracked databases before stopping the network
        // to ensure fdb_database_destroy() runs before fdb_stop_network().
        FoundationDB::closeAllDatabases();

        FoundationDB::reset();

        $this->fdb->fdb_stop_network();

        if ($this->networkThread instanceof \FFI\CData) {
            $this->pthread->pthread_join($this->networkThread->cdata, null);
        }

        // Close the dlopen'd handle now that fdb_run_network has completed.
        if ($this->fdbLibraryHandle instanceof \FFI\CData && !FFI::isNull($this->fdbLibraryHandle)) {
            $this->libdl->dlclose($this->fdbLibraryHandle);
            $this->fdbLibraryHandle = null;
        }

        $this->networkStarted = false;
        $this->networkSetup = false;
        $this->networkThread = null;

        // Invoke registered completion hooks after the network thread has
        // been joined and all native state has been torn down, so hooks can
        // safely flush traces/metrics. Registered hooks are consumed.
        $hooks = $this->networkCompletionHooks;
        $this->networkCompletionHooks = [];
        foreach ($hooks as $hook) {
            $hook();
        }
    }

    public function isNetworkStarted(): bool
    {
        return $this->networkStarted;
    }

    /** True once fdb_setup_network() has succeeded (possibly not started yet). */
    public function isNetworkSetup(): bool
    {
        return $this->networkSetup;
    }
}
