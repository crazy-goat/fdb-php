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

        typedef struct {
            const void* key;
            int key_length;
            const void* value;
            int value_length;
        } FDBKeyValue;

        typedef struct {
            const void* key;
            int key_length;
        } FDBKey;

        fdb_error_t fdb_select_api_version_impl(int runtime_version, int header_version);
        int fdb_get_max_api_version();
        const char* fdb_get_error(fdb_error_t code);

        fdb_error_t fdb_network_set_option(int option, const void* value, int value_length);
        fdb_error_t fdb_setup_network();
        fdb_error_t fdb_run_network();
        fdb_error_t fdb_stop_network();

        void fdb_future_destroy(FDBFuture* f);
        void fdb_future_release_memory(FDBFuture* f);
        void fdb_future_cancel(FDBFuture* f);
        fdb_error_t fdb_future_block_until_ready(FDBFuture* f);
        fdb_bool_t fdb_future_is_ready(FDBFuture* f);
        fdb_error_t fdb_future_get_error(FDBFuture* f);
        fdb_error_t fdb_future_get_int64(FDBFuture* f, int64_t* out);
        fdb_error_t fdb_future_get_key(FDBFuture* f, const char** out_key, int* out_key_length);
        fdb_error_t fdb_future_get_value(
            FDBFuture* f, fdb_bool_t* out_present, const char** out_value, int* out_value_length
        );
        fdb_error_t fdb_future_get_keyvalue_array(
            FDBFuture* f, const FDBKeyValue** out_kv, int* out_count, fdb_bool_t* out_more
        );
        fdb_error_t fdb_future_get_key_array(FDBFuture* f, const FDBKey** out_keys, int* out_count);
        fdb_error_t fdb_future_get_string_array(FDBFuture* f, const char*** out_strings, int* out_count);

        fdb_error_t fdb_create_database(const char* cluster_file_path, FDBDatabase** out_database);
        void fdb_database_destroy(FDBDatabase* d);
        fdb_error_t fdb_database_set_option(FDBDatabase* d, int option, const void* value, int value_length);
        fdb_error_t fdb_database_create_transaction(FDBDatabase* d, FDBTransaction** out_transaction);
        fdb_error_t fdb_database_open_tenant(
            FDBDatabase* d, const char* tenant_name, int tenant_name_length, FDBTenant** out_tenant
        );

        void fdb_tenant_destroy(FDBTenant* t);
        fdb_error_t fdb_tenant_create_transaction(FDBTenant* t, FDBTransaction** out_transaction);

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

    public readonly FFI $fdb;

    private readonly FFI $pthread;

    private readonly FFI $libdl;

    private bool $networkStarted = false;

    private ?CData $networkThread = null;

    private function __construct()
    {
        $this->fdb = FFI::cdef(self::FDB_HEADER, 'libfdb_c.so');
        $this->pthread = FFI::cdef(self::PTHREAD_HEADER, 'libpthread.so.0');
        $this->libdl = FFI::cdef(self::LIBDL_HEADER, 'libdl.so.2');
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

        $this->checkError($this->fdb->fdb_setup_network());

        $this->networkThread = $this->pthread->new('pthread_t');

        $fdbHandle = $this->libdl->dlopen('libfdb_c.so', self::RTLD_LAZY);
        if ($fdbHandle === null || FFI::isNull($fdbHandle)) {
            throw new \RuntimeException('Failed to dlopen libfdb_c.so: ' . FFI::string($this->libdl->dlerror()));
        }

        $runNetworkPtr = $this->libdl->dlsym($fdbHandle, 'fdb_run_network');
        if ($runNetworkPtr === null || FFI::isNull($runNetworkPtr)) {
            throw new \RuntimeException(
                'Failed to dlsym fdb_run_network: ' . FFI::string($this->libdl->dlerror()),
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

        $this->networkStarted = true;

        register_shutdown_function($this->stopNetwork(...));
    }

    public function stopNetwork(): void
    {
        if (!$this->networkStarted) {
            return;
        }

        $this->fdb->fdb_stop_network();

        if ($this->networkThread instanceof \FFI\CData) {
            $this->pthread->pthread_join($this->networkThread->cdata, null);
        }

        $this->networkStarted = false;
        $this->networkThread = null;
    }

    public function isNetworkStarted(): bool
    {
        return $this->networkStarted;
    }
}
