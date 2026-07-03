<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\AdminClient;
use CrazyGoat\FoundationDB\KeyUtil;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminClientTest extends TestCase
{
    /**
     * Call private AdminClient::decodeClusterStatusJson via reflection.
     *
     * @param string $json Raw JSON string
     */
    private function decodeClusterStatusJson(string $json): mixed
    {
        $method = new \ReflectionMethod(AdminClient::class, 'decodeClusterStatusJson');
        $admin = $this->createAdminClient();

        return $method->invoke($admin, $json);
    }

    /**
     * Create a minimal AdminClient instance without constructor using reflection.
     */
    private function createAdminClient(): AdminClient
    {
        $reflection = new \ReflectionClass(AdminClient::class);
        /** @var AdminClient $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        return $instance;
    }

    // -- decodeClusterStatusJson unit tests -----------------------------------

    #[Test]
    public function decodeValidJsonObject(): void
    {
        $result = $this->decodeClusterStatusJson('{"key": "value"}');

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function decodeValidJsonWithNestedData(): void
    {
        $result = $this->decodeClusterStatusJson('{"cluster": {"name": "test", "healthy": true}}');

        self::assertIsArray($result);
        self::assertArrayHasKey('cluster', $result);
        self::assertIsArray($result['cluster']);
        self::assertSame('test', $result['cluster']['name']);
        self::assertTrue($result['cluster']['healthy']);
    }

    #[Test]
    public function decodeThrowsOnNullLiteral(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid JSON object');

        $this->decodeClusterStatusJson('null');
    }

    #[Test]
    public function decodeThrowsOnNumber(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid JSON object');

        $this->decodeClusterStatusJson('42');
    }

    #[Test]
    public function decodeThrowsOnString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid JSON object');

        $this->decodeClusterStatusJson('"just a string"');
    }

    #[Test]
    public function decodeThrowsOnBoolean(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid JSON object');

        $this->decodeClusterStatusJson('true');
    }

    #[Test]
    public function decodeThrowsJsonExceptionOnSyntaxError(): void
    {
        $this->expectException(\JsonException::class);

        $this->decodeClusterStatusJson('{"broken":}');
    }

    #[Test]
    public function decodeThrowsJsonExceptionOnTruncatedJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->decodeClusterStatusJson('{"key"');
    }

    // -- listTenants end-key computation --------------------------------------

    #[Test]
    public function tenantRangeEndKeyIsStrictlyGreaterThanPrefix(): void
    {
        $reflection = new \ReflectionClass(AdminClient::class);
        $prefix = $reflection->getConstant('TENANT_MAP_PREFIX');
        if (!\is_string($prefix)) {
            self::fail('TENANT_MAP_PREFIX must be a string');
        }

        $end = KeyUtil::strinc($prefix);

        self::assertNotNull($end, 'strinc() must not return null for the tenant prefix');
        self::assertGreaterThan($prefix, $end);
    }

    #[Test]
    public function tenantRangeEndKeyCoversAllPossibleTenantNames(): void
    {
        $reflection = new \ReflectionClass(AdminClient::class);
        $prefix = $reflection->getConstant('TENANT_MAP_PREFIX');
        if (!\is_string($prefix)) {
            self::fail('TENANT_MAP_PREFIX must be a string');
        }

        $end = KeyUtil::strinc($prefix);
        self::assertNotNull($end);

        // Any tenant name whose first byte is any possible value (0x00–0xFF)
        // should fall within the range [prefix, end).
        // The end key should be prefix with the last byte incremented.
        $prefixLen = strlen($prefix);
        $endLen = strlen($end);

        // strinc drops trailing characters after the incremented byte.
        // The end key must be <= prefix length (it may be shorter).
        // Every possible key starting with prefix must be < end.
        // This means: end must equal prefix with last byte incremented
        // (and trailing bytes stripped).
        if ($prefix[$prefixLen - 1] === "\xff") {
            // If last byte is 0xFF, strinc carries to the previous byte.
            // In that case the end key will be shorter.
            self::assertLessThan($prefixLen, $endLen);
        } else {
            // Normal case: end = prefix with last byte incremented, last char dropped.
            /** @var int<0, 254> $lastByte */
            $lastByte = ord($prefix[$prefixLen - 1]);
            $expectedEnd = substr($prefix, 0, -1) . chr($lastByte + 1);
            self::assertSame($expectedEnd, $end);
        }

        // Verify that all tenant names produce keys within [prefix, end)
        $testNames = ['a', 'z', 'A', 'Z', '0', '9', '-', '_', '.', "\x00", "\x7f", "\xff", 'tenant-1', 'tenant-2'];
        foreach ($testNames as $name) {
            $key = $prefix . $name;
            self::assertGreaterThanOrEqual($prefix, $key, "Tenant key for '$name' must be >= prefix");
            self::assertLessThan($end, $key, "Tenant key for '$name' must be < end key");
        }
    }
}
