<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Guards the single source of the FDB port numbers (#189).
 *
 * The five `FDB_*_CONTAINER_PORT` values in `.env` are the source of truth:
 * `docker-compose.yml` references them for every service's `FDB_PORT`, the
 * port-mapping target and default, and the coordinator string, and
 * `bin/host-cluster-file.sh` builds the host-side cluster file from the same
 * values. These tests fail if a port literal returns to the compose file.
 */
final class ComposeFdbPortSingleSourceTest extends TestCase
{
    /**
     * The five container ports and the defaults documented in AGENTS.md.
     *
     * @var array<string, string>
     */
    private const CONTAINER_PORTS = [
        'FDB_COORD_1_CONTAINER_PORT' => '4500',
        'FDB_COORD_2_CONTAINER_PORT' => '4501',
        'FDB_COORD_3_CONTAINER_PORT' => '4502',
        'FDB_SERVER_1_CONTAINER_PORT' => '4510',
        'FDB_SERVER_2_CONTAINER_PORT' => '4511',
    ];

    /**
     * Host override variable and the container port it defaults to.
     *
     * @var array<int, array{string, string}>
     */
    private const PORT_PAIRS = [
        ['FDB_COORD_1_PORT', 'FDB_COORD_1_CONTAINER_PORT'],
        ['FDB_COORD_2_PORT', 'FDB_COORD_2_CONTAINER_PORT'],
        ['FDB_COORD_3_PORT', 'FDB_COORD_3_CONTAINER_PORT'],
        ['FDB_SERVER_1_PORT', 'FDB_SERVER_1_CONTAINER_PORT'],
        ['FDB_SERVER_2_PORT', 'FDB_SERVER_2_CONTAINER_PORT'],
    ];

    /**
     * The host-side cluster file lists the three coordinators only.
     *
     * @var array<int, array{string, string}>
     */
    private const COORDINATOR_PAIRS = [
        ['FDB_COORD_1_PORT', 'FDB_COORD_1_CONTAINER_PORT'],
        ['FDB_COORD_2_PORT', 'FDB_COORD_2_CONTAINER_PORT'],
        ['FDB_COORD_3_PORT', 'FDB_COORD_3_CONTAINER_PORT'],
    ];

    #[Test]
    public function envDefinesEveryContainerPortOnceWithItsDefault(): void
    {
        $env = self::repoFile('.env');

        foreach (self::CONTAINER_PORTS as $name => $default) {
            $definition = $name . '=' . $default;
            self::assertSame(
                1,
                substr_count($env, $definition),
                sprintf('.env must define %s exactly once', $definition),
            );
        }
    }

    #[Test]
    public function composeFileContainsNoHardcodedFdbPort(): void
    {
        $compose = self::repoFile('docker-compose.yml');

        foreach (self::CONTAINER_PORTS as $name => $default) {
            self::assertStringNotContainsString(
                $default,
                $compose,
                sprintf(
                    'docker-compose.yml must not contain the literal port %s (%s); reference ${%s} instead',
                    $default,
                    $name,
                    $name,
                ),
            );
        }
    }

    #[Test]
    public function coordinatorStringIsBuiltFromContainerPortVariables(): void
    {
        self::assertStringContainsString(
            'docker:docker@fdb-coord-1:${FDB_COORD_1_CONTAINER_PORT},'
            . 'fdb-coord-2:${FDB_COORD_2_CONTAINER_PORT},'
            . 'fdb-coord-3:${FDB_COORD_3_CONTAINER_PORT}',
            self::repoFile('docker-compose.yml'),
        );
    }

    #[Test]
    public function everyFdbServiceUsesTheContainerPortForEnvAndMapping(): void
    {
        $compose = self::repoFile('docker-compose.yml');

        foreach (self::PORT_PAIRS as [$hostPort, $containerPort]) {
            self::assertStringContainsString(
                sprintf('FDB_PORT: ${%s}', $containerPort),
                $compose,
                sprintf('a service env must read %s', $containerPort),
            );
            self::assertStringContainsString(
                sprintf('${%s:-${%s}}:${%s}', $hostPort, $containerPort, $containerPort),
                $compose,
                sprintf('the %s mapping must default to %s', $hostPort, $containerPort),
            );
        }
    }

    #[Test]
    public function hostClusterFileScriptReadsTheSameValues(): void
    {
        $script = self::repoFile('bin/host-cluster-file.sh');

        foreach (self::COORDINATOR_PAIRS as [$hostPort, $containerPort]) {
            self::assertStringContainsString(
                sprintf('${%s:-$%s}', $hostPort, $containerPort),
                $script,
                sprintf('bin/host-cluster-file.sh must default %s to %s', $hostPort, $containerPort),
            );
        }
    }

    private static function repoFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('unable to read %s', $relative));
        }

        return $contents;
    }
}
