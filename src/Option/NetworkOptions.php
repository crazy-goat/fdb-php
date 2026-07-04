<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Option;

use CrazyGoat\FoundationDB\KeyValueLimits;
use CrazyGoat\FoundationDB\NativeClient;

final readonly class NetworkOptions
{
    private const TRACE_ENABLE = 30;
    private const TRACE_ROLL_SIZE = 31;
    private const TRACE_MAX_LOGS_SIZE = 32;
    private const TRACE_LOG_GROUP = 33;
    private const TRACE_FORMAT = 34;
    private const TRACE_CLOCK_SOURCE = 35;
    private const TRACE_FILE_IDENTIFIER = 36;
    private const TRACE_SHARE_AMONG_CLIENT_THREADS = 37;
    private const TRACE_INITIALIZE_ON_SETUP = 38;
    private const TRACE_PARTIAL_FILE_SUFFIX = 39;
    private const KNOB = 40;
    private const TLS_CERT_BYTES = 42;
    private const TLS_CERT_PATH = 43;
    private const TLS_KEY_BYTES = 45;
    private const TLS_KEY_PATH = 46;
    private const TLS_VERIFY_PEERS = 47;
    private const TLS_CA_BYTES = 52;
    private const TLS_CA_PATH = 53;
    private const TLS_PASSWORD = 54;
    private const DISABLE_MULTI_VERSION_CLIENT_API = 60;
    private const CALLBACKS_ON_EXTERNAL_THREADS = 61;
    private const EXTERNAL_CLIENT_LIBRARY = 62;
    private const EXTERNAL_CLIENT_DIRECTORY = 63;
    private const DISABLE_LOCAL_CLIENT = 64;
    private const CLIENT_THREADS_PER_VERSION = 65;
    private const DISABLE_CLIENT_STATISTICS_LOGGING = 70;
    private const ENABLE_RUN_LOOP_PROFILING = 71;
    private const DISABLE_CLIENT_BYPASS = 72;
    private const CLIENT_TMP_DIR = 91;

    public function __construct(
        private NativeClient $client,
    ) {
    }

    public function setTraceEnable(?string $outputDirectory = null): self
    {
        $this->setOption(self::TRACE_ENABLE, $outputDirectory);

        return $this;
    }

    public function setTraceRollSize(int $maxSize): self
    {
        $this->setIntOption(self::TRACE_ROLL_SIZE, $maxSize);

        return $this;
    }

    public function setTraceMaxLogsSize(int $maxSize): self
    {
        $this->setIntOption(self::TRACE_MAX_LOGS_SIZE, $maxSize);

        return $this;
    }

    public function setTraceLogGroup(string $logGroup): self
    {
        $this->setOption(self::TRACE_LOG_GROUP, $logGroup);

        return $this;
    }

    public function setTraceFormat(string $format): self
    {
        $this->setOption(self::TRACE_FORMAT, $format);

        return $this;
    }

    public function setTraceClockSource(string $source): self
    {
        $this->setOption(self::TRACE_CLOCK_SOURCE, $source);

        return $this;
    }

    public function setTraceFileIdentifier(string $identifier): self
    {
        $this->setOption(self::TRACE_FILE_IDENTIFIER, $identifier);

        return $this;
    }

    public function setTraceShareAmongClientThreads(): self
    {
        $this->setOption(self::TRACE_SHARE_AMONG_CLIENT_THREADS);

        return $this;
    }

    public function setTraceInitializeOnSetup(): self
    {
        $this->setOption(self::TRACE_INITIALIZE_ON_SETUP);

        return $this;
    }

    public function setTracePartialFileSuffix(string $suffix): self
    {
        $this->setOption(self::TRACE_PARTIAL_FILE_SUFFIX, $suffix);

        return $this;
    }

    public function setKnob(string $knob): self
    {
        $this->setOption(self::KNOB, $knob);

        return $this;
    }

    public function setTlsCertBytes(string $certificates): self
    {
        $this->setOption(self::TLS_CERT_BYTES, $certificates);

        return $this;
    }

    public function setTlsCertPath(string $path): self
    {
        $this->setOption(self::TLS_CERT_PATH, $path);

        return $this;
    }

    public function setTlsKeyBytes(string $key): self
    {
        $this->setOption(self::TLS_KEY_BYTES, $key);

        return $this;
    }

    public function setTlsKeyPath(string $path): self
    {
        $this->setOption(self::TLS_KEY_PATH, $path);

        return $this;
    }

    public function setTlsVerifyPeers(string $pattern): self
    {
        $this->setOption(self::TLS_VERIFY_PEERS, $pattern);

        return $this;
    }

    public function setTlsCaBytes(string $caBundle): self
    {
        $this->setOption(self::TLS_CA_BYTES, $caBundle);

        return $this;
    }

    public function setTlsCaPath(string $path): self
    {
        $this->setOption(self::TLS_CA_PATH, $path);

        return $this;
    }

    public function setTlsPassword(string $password): self
    {
        $this->setOption(self::TLS_PASSWORD, $password);

        return $this;
    }

    public function setDisableMultiVersionClientApi(): self
    {
        $this->setOption(self::DISABLE_MULTI_VERSION_CLIENT_API);

        return $this;
    }

    public function setCallbacksOnExternalThreads(): self
    {
        $this->setOption(self::CALLBACKS_ON_EXTERNAL_THREADS);

        return $this;
    }

    public function setExternalClientLibrary(string $path): self
    {
        $this->setOption(self::EXTERNAL_CLIENT_LIBRARY, $path);

        return $this;
    }

    public function setExternalClientDirectory(string $path): self
    {
        $this->setOption(self::EXTERNAL_CLIENT_DIRECTORY, $path);

        return $this;
    }

    public function setDisableLocalClient(): self
    {
        $this->setOption(self::DISABLE_LOCAL_CLIENT);

        return $this;
    }

    public function setClientThreadsPerVersion(int $threads): self
    {
        $this->setIntOption(self::CLIENT_THREADS_PER_VERSION, $threads);

        return $this;
    }

    public function setDisableClientStatisticsLogging(): self
    {
        $this->setOption(self::DISABLE_CLIENT_STATISTICS_LOGGING);

        return $this;
    }

    public function setEnableRunLoopProfiling(): self
    {
        $this->setOption(self::ENABLE_RUN_LOOP_PROFILING);

        return $this;
    }

    public function setDisableClientBypass(): self
    {
        $this->setOption(self::DISABLE_CLIENT_BYPASS);

        return $this;
    }

    public function setClientTmpDir(string $path): self
    {
        $this->setOption(self::CLIENT_TMP_DIR, $path);

        return $this;
    }

    private function setOption(int $code, ?string $value = null): void
    {
        if ($value !== null) {
            $valueLength = KeyValueLimits::assertValidFfiLength($value, 'Network option value');
        } else {
            $valueLength = 0;
        }

        $this->client->checkError(
            $this->client->fdb->fdb_network_set_option(
                $code,
                $value,
                $valueLength,
            ),
        );
    }

    private function setIntOption(int $code, int $value): void
    {
        $this->setOption($code, pack('P', $value));
    }
}
