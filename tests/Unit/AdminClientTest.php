<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\AdminClient;
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
}
