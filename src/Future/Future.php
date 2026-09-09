<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\NativeClient;
use FFI\CData;

abstract class Future
{
    /** Poll interval (microseconds) used by awaitAll() while spinning. */
    private const AWAIT_ALL_POLL_INTERVAL_US = 1000;

    protected bool $resolved = false;

    /**
     * Cached error state, captured in blockUntilReady() while the future's
     * memory is still valid. Null while the future has not been awaited yet.
     */
    protected ?bool $errorState = null;

    /** @var list<callable(static): void> */
    private array $onReadyHooks = [];

    public function __construct(
        protected CData $fpointer,
        protected readonly NativeClient $client,
    ) {
    }

    public function isReady(): bool
    {
        return (bool) $this->client->fdb->fdb_future_is_ready($this->fpointer);
    }

    /**
     * Reports whether the future resolved to an error. Only meaningful once
     * the future is ready — call isReady() first or blockUntilReady() via
     * await(), and unlike await()/blockUntilReady() this does not throw the
     * error, it merely reports it.
     *
     * Note: this is deliberately NOT bound to fdb_future_is_error, which is
     * a removed-API stub in fdb_c.h for API versions >= 23 (calling it on a
     * modern libfdb_c aborts the process with "REMOVED FDB API FUNCTION").
     * The equivalent, safe source of truth is fdb_future_get_error(), which
     * returns the error code (0 when the future succeeded).
     */
    public function isError(): bool
    {
        if ($this->errorState !== null) {
            // The state was captured while the future's memory was still
            // valid; after releaseMemory() querying the handle is undefined.
            return $this->errorState;
        }

        return $this->client->fdb->fdb_future_get_error($this->fpointer) !== 0;
    }

    public function cancel(): void
    {
        $this->client->fdb->fdb_future_cancel($this->fpointer);
    }

    /**
     * Registers a hook that runs exactly once, after the future has become
     * ready (before its payload is read by await()).
     *
     * The hook always executes on the PHP thread that resolved the future —
     * never on the FDB network thread. Under the current blocking model the
     * hook fires when the future is resolved via await() (or by awaitAll()).
     * Hooks registered after the future has already been resolved fire
     * immediately. Exceptions thrown from a hook propagate to the caller.
     *
     * @param callable(static): void $fn
     */
    public function onReady(callable $fn): void
    {
        if ($this->resolved) {
            $fn($this);

            return;
        }

        $this->onReadyHooks[] = $fn;
    }

    /**
     * Awaits many futures together instead of serializing them: all pending
     * requests are already in flight, so total wait time is driven by the
     * slowest future (roughly one round trip) rather than the sum of all of
     * them. N futures awaited one-by-one with await() cost N round trips.
     *
     * Polls the non-blocking fdb_future_is_ready() for every future at once
     * (no PHP code ever runs on the FDB network thread), then resolves the
     * results in the original order. Futures are resolved strictly after all
     * of them are ready, so an error in an early future does not cancel the
     * wait for the rest; errors are still thrown from await() in input order.
     *
     * @param array<Future> $futures
     * @return array<int|string, mixed> results of every future, keyed like the input
     */
    public static function awaitAll(array $futures): array
    {
        $pending = [];
        foreach ($futures as $key => $future) {
            if (!$future->resolved && !$future->isReady()) {
                $pending[$key] = $future;
            }
        }

        while ($pending !== []) {
            usleep(self::AWAIT_ALL_POLL_INTERVAL_US);
            foreach ($pending as $key => $future) {
                if ($future->isReady()) {
                    unset($pending[$key]);
                }
            }
        }

        $results = [];
        foreach ($futures as $key => $future) {
            $results[$key] = $future->await();
        }

        return $results;
    }

    abstract public function await(): mixed;

    protected function blockUntilReady(): void
    {
        $this->client->checkError(
            $this->client->fdb->fdb_future_block_until_ready($this->fpointer),
        );
        // Capture the error code before releaseMemory() invalidates the
        // future's payload: fdb_future_get_error() must not be consulted
        // after the memory has been released.
        $errorCode = $this->client->fdb->fdb_future_get_error($this->fpointer);
        $this->errorState = $errorCode !== 0;
        $this->fireOnReadyHooks();
        if ($errorCode !== 0) {
            $this->client->checkError($errorCode);
        }
    }

    private function fireOnReadyHooks(): void
    {
        if ($this->onReadyHooks === []) {
            return;
        }

        $hooks = $this->onReadyHooks;
        $this->onReadyHooks = [];
        foreach ($hooks as $hook) {
            $hook($this);
        }
    }

    protected function releaseMemory(): void
    {
        $this->client->fdb->fdb_future_release_memory($this->fpointer);
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_future_destroy($this->fpointer);
    }
}
