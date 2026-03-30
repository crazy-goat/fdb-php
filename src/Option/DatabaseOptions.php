<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Option;

use CrazyGoat\FoundationDB\Database;

final readonly class DatabaseOptions
{
    private const LOCATION_CACHE_SIZE = 10;
    private const MAX_WATCHES = 20;
    private const MACHINE_ID = 21;
    private const DATACENTER_ID = 22;
    private const SNAPSHOT_RYW_ENABLE = 26;
    private const SNAPSHOT_RYW_DISABLE = 27;
    private const TRANSACTION_LOGGING_MAX_FIELD_LENGTH = 405;
    private const TRANSACTION_TIMEOUT = 500;
    private const TRANSACTION_RETRY_LIMIT = 501;
    private const TRANSACTION_MAX_RETRY_DELAY = 502;
    private const TRANSACTION_SIZE_LIMIT = 503;
    private const TRANSACTION_CAUSAL_READ_RISKY = 504;
    private const TRANSACTION_AUTOMATIC_IDEMPOTENCY = 506;
    private const TRANSACTION_BYPASS_UNREADABLE = 700;
    private const TRANSACTION_USED_DURING_COMMIT_PROTECTION_DISABLE = 701;
    private const TRANSACTION_REPORT_CONFLICTING_KEYS = 702;

    public function __construct(
        private Database $database,
    ) {
    }

    public function setLocationCacheSize(int $maxEntries): self
    {
        $this->setIntOption(self::LOCATION_CACHE_SIZE, $maxEntries);

        return $this;
    }

    public function setMaxWatches(int $maxWatches): self
    {
        $this->setIntOption(self::MAX_WATCHES, $maxWatches);

        return $this;
    }

    public function setMachineId(string $id): self
    {
        $this->database->setOption(self::MACHINE_ID, $id);

        return $this;
    }

    public function setDatacenterId(string $id): self
    {
        $this->database->setOption(self::DATACENTER_ID, $id);

        return $this;
    }

    public function setSnapshotRywEnable(): self
    {
        $this->database->setOption(self::SNAPSHOT_RYW_ENABLE);

        return $this;
    }

    public function setSnapshotRywDisable(): self
    {
        $this->database->setOption(self::SNAPSHOT_RYW_DISABLE);

        return $this;
    }

    public function setTransactionLoggingMaxFieldLength(int $maxLength): self
    {
        $this->setIntOption(self::TRANSACTION_LOGGING_MAX_FIELD_LENGTH, $maxLength);

        return $this;
    }

    public function setTransactionTimeout(int $milliseconds): self
    {
        $this->setIntOption(self::TRANSACTION_TIMEOUT, $milliseconds);

        return $this;
    }

    public function setTransactionRetryLimit(int $retries): self
    {
        $this->setIntOption(self::TRANSACTION_RETRY_LIMIT, $retries);

        return $this;
    }

    public function setTransactionMaxRetryDelay(int $milliseconds): self
    {
        $this->setIntOption(self::TRANSACTION_MAX_RETRY_DELAY, $milliseconds);

        return $this;
    }

    public function setTransactionSizeLimit(int $bytes): self
    {
        $this->setIntOption(self::TRANSACTION_SIZE_LIMIT, $bytes);

        return $this;
    }

    public function setTransactionCausalReadRisky(): self
    {
        $this->database->setOption(self::TRANSACTION_CAUSAL_READ_RISKY);

        return $this;
    }

    public function setTransactionAutomaticIdempotency(): self
    {
        $this->database->setOption(self::TRANSACTION_AUTOMATIC_IDEMPOTENCY);

        return $this;
    }

    public function setTransactionBypassUnreadable(): self
    {
        $this->database->setOption(self::TRANSACTION_BYPASS_UNREADABLE);

        return $this;
    }

    public function setTransactionUsedDuringCommitProtectionDisable(): self
    {
        $this->database->setOption(self::TRANSACTION_USED_DURING_COMMIT_PROTECTION_DISABLE);

        return $this;
    }

    public function setTransactionReportConflictingKeys(): self
    {
        $this->database->setOption(self::TRANSACTION_REPORT_CONFLICTING_KEYS);

        return $this;
    }

    private function setIntOption(int $code, int $value): void
    {
        $this->database->setOption($code, pack('P', $value));
    }
}
