<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Option;

use CrazyGoat\FoundationDB\Transaction;

final readonly class TransactionOptions
{
    private const CAUSAL_WRITE_RISKY = 10;
    private const CAUSAL_READ_RISKY = 20;
    private const CAUSAL_READ_DISABLE = 21;
    private const INCLUDE_PORT_IN_ADDRESS = 23;
    private const NEXT_WRITE_NO_WRITE_CONFLICT_RANGE = 30;
    private const READ_YOUR_WRITES_DISABLE = 51;
    private const READ_SERVER_SIDE_CACHE_ENABLE = 507;
    private const READ_SERVER_SIDE_CACHE_DISABLE = 508;
    private const READ_PRIORITY_NORMAL = 509;
    private const READ_PRIORITY_LOW = 510;
    private const READ_PRIORITY_HIGH = 511;
    private const DURABILITY_DATACENTER = 110;
    private const DURABILITY_RISKY = 120;
    private const PRIORITY_SYSTEM_IMMEDIATE = 200;
    private const PRIORITY_BATCH = 201;
    private const ACCESS_SYSTEM_KEYS = 301;
    private const READ_SYSTEM_KEYS = 302;
    private const RAW_ACCESS = 303;
    private const BYPASS_STORAGE_QUOTA = 304;
    private const DEBUG_RETRY_LOGGING = 401;
    private const DEBUG_TRANSACTION_IDENTIFIER = 403;
    private const LOG_TRANSACTION = 404;
    private const TRANSACTION_LOGGING_MAX_FIELD_LENGTH = 405;
    private const SERVER_REQUEST_TRACING = 406;
    private const TIMEOUT = 500;
    private const RETRY_LIMIT = 501;
    private const MAX_RETRY_DELAY = 502;
    private const SIZE_LIMIT = 503;
    private const SNAPSHOT_RYW_ENABLE = 600;
    private const SNAPSHOT_RYW_DISABLE = 601;
    private const LOCK_AWARE = 700;
    private const USED_DURING_COMMIT_PROTECTION_DISABLE = 701;
    private const READ_LOCK_AWARE = 702;
    private const USE_PROVISIONAL_PROXIES = 711;
    private const REPORT_CONFLICTING_KEYS = 712;
    private const SPECIAL_KEY_SPACE_RELAXED = 713;
    private const SPECIAL_KEY_SPACE_ENABLE_WRITES = 714;
    private const TAG = 800;
    private const AUTO_THROTTLE_TAG = 801;
    private const BYPASS_UNREADABLE = 1100;
    private const USE_GRV_CACHE = 1101;

    public function __construct(
        private Transaction $transaction,
    ) {
    }

    public function setCausalWriteRisky(): self
    {
        $this->transaction->setOption(self::CAUSAL_WRITE_RISKY);

        return $this;
    }

    public function setCausalReadRisky(): self
    {
        $this->transaction->setOption(self::CAUSAL_READ_RISKY);

        return $this;
    }

    public function setCausalReadDisable(): self
    {
        $this->transaction->setOption(self::CAUSAL_READ_DISABLE);

        return $this;
    }

    public function setIncludePortInAddress(): self
    {
        $this->transaction->setOption(self::INCLUDE_PORT_IN_ADDRESS);

        return $this;
    }

    public function setNextWriteNoWriteConflictRange(): self
    {
        $this->transaction->setOption(self::NEXT_WRITE_NO_WRITE_CONFLICT_RANGE);

        return $this;
    }

    public function setReadYourWritesDisable(): self
    {
        $this->transaction->setOption(self::READ_YOUR_WRITES_DISABLE);

        return $this;
    }

    public function setReadServerSideCacheEnable(): self
    {
        $this->transaction->setOption(self::READ_SERVER_SIDE_CACHE_ENABLE);

        return $this;
    }

    public function setReadServerSideCacheDisable(): self
    {
        $this->transaction->setOption(self::READ_SERVER_SIDE_CACHE_DISABLE);

        return $this;
    }

    public function setReadPriorityNormal(): self
    {
        $this->transaction->setOption(self::READ_PRIORITY_NORMAL);

        return $this;
    }

    public function setReadPriorityLow(): self
    {
        $this->transaction->setOption(self::READ_PRIORITY_LOW);

        return $this;
    }

    public function setReadPriorityHigh(): self
    {
        $this->transaction->setOption(self::READ_PRIORITY_HIGH);

        return $this;
    }

    public function setDurabilityDatacenter(): self
    {
        $this->transaction->setOption(self::DURABILITY_DATACENTER);

        return $this;
    }

    public function setDurabilityRisky(): self
    {
        $this->transaction->setOption(self::DURABILITY_RISKY);

        return $this;
    }

    public function setPrioritySystemImmediate(): self
    {
        $this->transaction->setOption(self::PRIORITY_SYSTEM_IMMEDIATE);

        return $this;
    }

    public function setPriorityBatch(): self
    {
        $this->transaction->setOption(self::PRIORITY_BATCH);

        return $this;
    }

    public function setAccessSystemKeys(): self
    {
        $this->transaction->setOption(self::ACCESS_SYSTEM_KEYS);

        return $this;
    }

    public function setReadSystemKeys(): self
    {
        $this->transaction->setOption(self::READ_SYSTEM_KEYS);

        return $this;
    }

    public function setRawAccess(): self
    {
        $this->transaction->setOption(self::RAW_ACCESS);

        return $this;
    }

    public function setBypassStorageQuota(): self
    {
        $this->transaction->setOption(self::BYPASS_STORAGE_QUOTA);

        return $this;
    }

    public function setDebugRetryLogging(?string $transactionName = null): self
    {
        $this->transaction->setOption(self::DEBUG_RETRY_LOGGING, $transactionName);

        return $this;
    }

    public function setDebugTransactionIdentifier(string $identifier): self
    {
        $this->transaction->setOption(self::DEBUG_TRANSACTION_IDENTIFIER, $identifier);

        return $this;
    }

    public function setLogTransaction(): self
    {
        $this->transaction->setOption(self::LOG_TRANSACTION);

        return $this;
    }

    public function setTransactionLoggingMaxFieldLength(int $maxLength): self
    {
        $this->setIntOption(self::TRANSACTION_LOGGING_MAX_FIELD_LENGTH, $maxLength);

        return $this;
    }

    public function setServerRequestTracing(): self
    {
        $this->transaction->setOption(self::SERVER_REQUEST_TRACING);

        return $this;
    }

    public function setTimeout(int $milliseconds): self
    {
        $this->setIntOption(self::TIMEOUT, $milliseconds);

        return $this;
    }

    public function setRetryLimit(int $retries): self
    {
        $this->setIntOption(self::RETRY_LIMIT, $retries);

        return $this;
    }

    public function setMaxRetryDelay(int $milliseconds): self
    {
        $this->setIntOption(self::MAX_RETRY_DELAY, $milliseconds);

        return $this;
    }

    public function setSizeLimit(int $bytes): self
    {
        $this->setIntOption(self::SIZE_LIMIT, $bytes);

        return $this;
    }

    public function setSnapshotRywEnable(): self
    {
        $this->transaction->setOption(self::SNAPSHOT_RYW_ENABLE);

        return $this;
    }

    public function setSnapshotRywDisable(): self
    {
        $this->transaction->setOption(self::SNAPSHOT_RYW_DISABLE);

        return $this;
    }

    public function setLockAware(): self
    {
        $this->transaction->setOption(self::LOCK_AWARE);

        return $this;
    }

    public function setUsedDuringCommitProtectionDisable(): self
    {
        $this->transaction->setOption(self::USED_DURING_COMMIT_PROTECTION_DISABLE);

        return $this;
    }

    public function setReadLockAware(): self
    {
        $this->transaction->setOption(self::READ_LOCK_AWARE);

        return $this;
    }

    public function setUseProvisionalProxies(): self
    {
        $this->transaction->setOption(self::USE_PROVISIONAL_PROXIES);

        return $this;
    }

    public function setReportConflictingKeys(): self
    {
        $this->transaction->setOption(self::REPORT_CONFLICTING_KEYS);

        return $this;
    }

    public function setSpecialKeySpaceRelaxed(): self
    {
        $this->transaction->setOption(self::SPECIAL_KEY_SPACE_RELAXED);

        return $this;
    }

    public function setSpecialKeySpaceEnableWrites(): self
    {
        $this->transaction->setOption(self::SPECIAL_KEY_SPACE_ENABLE_WRITES);

        return $this;
    }

    public function setTag(string $tag): self
    {
        $this->transaction->setOption(self::TAG, $tag);

        return $this;
    }

    public function setAutoThrottleTag(string $tag): self
    {
        $this->transaction->setOption(self::AUTO_THROTTLE_TAG, $tag);

        return $this;
    }

    public function setBypassUnreadable(): self
    {
        $this->transaction->setOption(self::BYPASS_UNREADABLE);

        return $this;
    }

    public function setUseGrvCache(): self
    {
        $this->transaction->setOption(self::USE_GRV_CACHE);

        return $this;
    }

    private function setIntOption(int $code, int $value): void
    {
        $this->transaction->setOption($code, pack('P', $value));
    }
}
