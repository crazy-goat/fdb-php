# Options Documentation

## Overview

Three option classes with fluent API: NetworkOptions, DatabaseOptions, TransactionOptions.

## Network Options

Set before opening database:

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;

FDB::apiVersion(730);

FDB::networkOptions()
    ->setTraceEnable('/var/log/fdb/')
    ->setTraceFormat('json')
    ->setTraceLogGroup('my-app');

$db = FDB::open();
```

### Key Methods

**Tracing:**
- `setTraceEnable` — Enable trace logging to directory
- `setTraceFormat` — Set trace format ('json' or 'xml')
- `setTraceRollSize` — Set trace file roll size
- `setTraceMaxLogsSize` — Set maximum total log size
- `setTraceLogGroup` — Set log group identifier
- `setTraceClockSource` — Set clock source for traces
- `setTraceFileIdentifier` — Set trace file identifier
- `setTraceShareAmongClientThreads` — Share trace files across threads
- `setTraceInitializeOnSetup` — Initialize tracing on setup
- `setTracePartialFileSuffix` — Set partial file suffix

**TLS:**
- `setTlsCertBytes` — Set TLS certificate from bytes
- `setTlsCertPath` — Set TLS certificate from file path
- `setTlsKeyBytes` — Set TLS key from bytes
- `setTlsKeyPath` — Set TLS key from file path
- `setTlsVerifyPeers` — Set TLS peer verification
- `setTlsCaBytes` — Set CA certificate from bytes
- `setTlsCaPath` — Set CA certificate from file path
- `setTlsPassword` — Set TLS password

**Multi-version:**
- `setDisableMultiVersionClientApi` — Disable multi-version client API
- `setExternalClientLibrary` — Load external client library
- `setExternalClientDirectory` — Set external client directory
- `setDisableLocalClient` — Disable local client
- `setClientThreadsPerVersion` — Set client threads per version

**Other:**
- `setKnob` — Set arbitrary knob value
- `setCallbacksOnExternalThreads` — Enable callbacks on external threads
- `setDisableClientStatisticsLogging` — Disable client statistics logging
- `setEnableRunLoopProfiling` — Enable run loop profiling
- `setDisableClientBypass` — Disable client bypass
- `setClientTmpDir` — Set client temporary directory

## Database Options

Set after opening database:

```php
$db->options()
    ->setTransactionTimeout(10_000)
    ->setTransactionRetryLimit(5)
    ->setTransactionMaxRetryDelay(1000);
```

### Key Methods

**Transaction defaults:**
- `setTransactionTimeout` — Default transaction timeout (ms)
- `setTransactionRetryLimit` — Default retry limit
- `setTransactionMaxRetryDelay` — Default max retry delay (ms)
- `setTransactionSizeLimit` — Default transaction size limit

**Cache:**
- `setLocationCacheSize` — Set location cache size
- `setMaxWatches` — Set maximum watches

**Identity:**
- `setMachineId` — Set machine identifier
- `setDatacenterId` — Set datacenter identifier

**Snapshot:**
- `setSnapshotRywEnable` — Enable snapshot read-your-writes
- `setSnapshotRywDisable` — Disable snapshot read-your-writes

**Other:**
- `setTransactionLoggingMaxFieldLength` — Set max field length for logging
- `setTransactionCausalReadRisky` — Enable risky causal reads
- `setTransactionAutomaticIdempotency` — Enable automatic idempotency
- `setTransactionBypassUnreadable` — Bypass unreadable keys
- `setTransactionUsedDuringCommitProtectionDisable` — Disable commit protection
- `setTransactionReportConflictingKeys` — Report conflicting keys

## Transaction Options

Set within transaction:

```php
$db->transact(function (Transaction $tr) {
    $tr->options()
        ->setTimeout(5000)
        ->setRetryLimit(3)
        ->setPriorityBatch()
        ->setReadYourWritesDisable();
    
    // ... operations
});
```

### Key Methods

**Timeouts:**
- `setTimeout` — Transaction timeout (ms)
- `setRetryLimit` — Retry limit
- `setMaxRetryDelay` — Max retry delay (ms)
- `setSizeLimit` — Transaction size limit

**Priority:**
- `setPrioritySystemImmediate` — System immediate priority
- `setPriorityBatch` — Batch priority

**Read priority:**
- `setReadPriorityNormal` — Normal read priority
- `setReadPriorityLow` — Low read priority
- `setReadPriorityHigh` — High read priority

**Causal:**
- `setCausalWriteRisky` — Risky causal writes
- `setCausalReadRisky` — Risky causal reads
- `setCausalReadDisable` — Disable causal reads

**Durability:**
- `setDurabilityDatacenter` — Datacenter durability
- `setDurabilityRisky` — Risky durability

**System keys:**
- `setAccessSystemKeys` — Access system keys
- `setReadSystemKeys` — Read system keys
- `setRawAccess` — Raw access mode

**Snapshot:**
- `setSnapshotRywEnable` — Enable snapshot read-your-writes
- `setSnapshotRywDisable` — Disable snapshot read-your-writes

**Debugging:**
- `setDebugRetryLogging` — Enable retry logging
- `setDebugTransactionIdentifier` — Set transaction identifier
- `setLogTransaction` — Log transaction
- `setTransactionLoggingMaxFieldLength` — Set max field length
- `setServerRequestTracing` — Enable server request tracing

**Conflict:**
- `setNextWriteNoWriteConflictRange` — Skip conflict range for next write
- `setReadYourWritesDisable` — Disable read-your-writes
- `setReportConflictingKeys` — Report conflicting keys

**Special keys:**
- `setSpecialKeySpaceRelaxed` — Relaxed special key space
- `setSpecialKeySpaceEnableWrites` — Enable writes to special key space

**Other:**
- `setLockAware` — Lock aware mode
- `setReadLockAware` — Read lock aware
- `setUsedDuringCommitProtectionDisable` — Disable commit protection
- `setUseProvisionalProxies` — Use provisional proxies
- `setTag` — Set transaction tag
- `setAutoThrottleTag` — Set auto-throttle tag
- `setBypassUnreadable` — Bypass unreadable
- `setUseGrvCache` — Use GRV cache
- `setBypassStorageQuota` — Bypass storage quota
- `setIncludePortInAddress` — Include port in address
- `setReadServerSideCacheEnable` — Enable server-side cache
- `setReadServerSideCacheDisable` — Disable server-side cache

## Fluent API

All setters return `self`, enabling method chaining:

```php
$tr->options()
    ->setTimeout(5000)
    ->setRetryLimit(3)
    ->setPriorityBatch();
```
