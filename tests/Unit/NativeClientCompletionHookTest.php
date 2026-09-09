<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\NativeClient;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the network-thread completion hook registry added in
 * issue #94 (`NativeClient::onNetworkThreadCompletion()`).
 *
 * The hooks must be invoked exactly once from `stopNetwork()`, strictly
 * after the network thread would have been joined, and consumed afterwards.
 * No real FDB network is needed: `stopNetwork()` is exercised on a
 * constructor-less NativeClient whose FFI handle points at a tiny stub
 * library, with the "network started" state injected via reflection.
 */
final class NativeClientCompletionHookTest extends TestCase
{
    protected function tearDown(): void
    {
        FoundationDB::reset();
        NativeClient::resetInstance();
    }

    #[Test]
    public function stopNetworkInvokesRegisteredHooksOnceInFifoOrder(): void
    {
        $client = $this->makeStartedClient();
        $calls = [];
        $client->onNetworkThreadCompletion(function () use (&$calls): void {
            $calls[] = 'first';
        });
        $client->onNetworkThreadCompletion(function () use (&$calls): void {
            $calls[] = 'second';
        });

        $client->stopNetwork();

        self::assertSame(['first', 'second'], $calls);
    }

    #[Test]
    public function hooksAreConsumedAfterStopNetwork(): void
    {
        $client = $this->makeStartedClient();
        $calls = 0;
        $client->onNetworkThreadCompletion(function () use (&$calls): void {
            ++$calls;
        });

        $client->stopNetwork();
        // A second stop is a no-op (network no longer started).
        $client->stopNetwork();

        self::assertSame(1, $calls);
        self::assertSame([], $client->getNetworkCompletionHooks());
    }

    #[Test]
    public function stopNetworkWithoutHooksIsAPlainShutdown(): void
    {
        $client = $this->makeStartedClient();

        $client->stopNetwork();

        self::assertFalse($client->isNetworkStarted());
    }

    #[Test]
    public function hooksRegisteredOnANotStartedNetworkAreKept(): void
    {
        $client = $this->makeStartedClient();
        $client->stopNetwork();
        $calls = 0;
        $client->onNetworkThreadCompletion(function () use (&$calls): void {
            ++$calls;
        });

        // Not started: stopNetwork() is a no-op and must not invoke hooks
        // prematurely — they stay registered for the eventual shutdown.
        $client->stopNetwork();

        self::assertSame(0, $calls);
        self::assertCount(1, $client->getNetworkCompletionHooks());
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * A NativeClient in the "network started" state, with the FDB FFI handle
     * pointed at a stub library that provides a no-op fdb_stop_network().
     */
    private function makeStartedClient(): NativeClient
    {
        if (!extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is not available');
        }

        $stub = $this->buildStub();

        $client = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($client, 'fdb', $stub);

        $state = new \ReflectionClass(NativeClient::class);
        $state->getProperty('networkStarted')->setValue($client, true);
        $state->getProperty('networkThread')->setValue($client, null);

        return $client;
    }

    private function buildStub(): FFI
    {
        $source = <<<'C'
            int fdb_stop_network(void) { return 0; }
            C;

        $header = <<<'C'
            int fdb_stop_network(void);
            C;

        $cacheKey = md5($source . $header . PHP_VERSION . PHP_OS_FAMILY);
        $libraryPath = sys_get_temp_dir() . '/fdb-php-phpunit-stub-' . $cacheKey . '.so';
        $sourcePath = sys_get_temp_dir() . '/fdb-php-phpunit-stub-' . $cacheKey . '.c';

        if (!is_file($libraryPath)) {
            file_put_contents($sourcePath, $source);

            $flags = PHP_OS_FAMILY === 'Darwin' ? '-dynamiclib' : '-shared';
            $command = sprintf(
                'cc %s -fPIC -o %s %s 2>&1',
                $flags,
                escapeshellarg($libraryPath),
                escapeshellarg($sourcePath),
            );
            exec($command, $outputLines, $exitCode);

            if ($exitCode !== 0) {
                self::markTestSkipped(sprintf(
                    'Cannot compile the FDB stub (%s): %s',
                    $command,
                    implode("\n", $outputLines),
                ));
            }
        }

        try {
            return FFI::cdef($header, $libraryPath);
        } catch (\Throwable $e) {
            self::markTestSkipped('Cannot load the FDB stub: ' . $e->getMessage());
        }
    }

    private function initializeReadOnly(object $object, string $property, mixed $value): void
    {
        $declaringClass = (new \ReflectionProperty($object, $property))->getDeclaringClass()->getName();
        $property = new \ReflectionProperty($declaringClass, $property);
        $property->setValue($object, $value);
    }
}
