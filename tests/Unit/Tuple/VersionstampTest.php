<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\Versionstamp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VersionstampTest extends TestCase
{
    #[Test]
    public function constructorAcceptsValid10ByteTrVersionAndZeroUserVersion(): void
    {
        $trVersion = str_repeat("\x01", 10);
        $vs = new Versionstamp($trVersion, 0);
        self::assertSame($trVersion, $vs->trVersion);
        self::assertSame(0, $vs->userVersion);
    }

    #[Test]
    public function constructorAcceptsMaxUserVersion(): void
    {
        $trVersion = str_repeat("\x00", 10);
        $vs = new Versionstamp($trVersion, 65535);
        self::assertSame(65535, $vs->userVersion);
    }

    #[Test]
    public function constructorThrowsOnTrVersionTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction version must be exactly 10 bytes, got 9');
        new Versionstamp(str_repeat("\x00", 9), 0);
    }

    #[Test]
    public function constructorThrowsOnTrVersionTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction version must be exactly 10 bytes, got 11');
        new Versionstamp(str_repeat("\x00", 11), 0);
    }

    #[Test]
    public function constructorThrowsOnNegativeUserVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User version must be between 0 and 65535, got -1');
        new Versionstamp(str_repeat("\x00", 10), -1);
    }

    #[Test]
    public function constructorThrowsOnUserVersionTooLarge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User version must be between 0 and 65535, got 65536');
        new Versionstamp(str_repeat("\x00", 10), 65536);
    }

    #[Test]
    public function isCompleteReturnsTrueForNonAllFfTrVersion(): void
    {
        $vs = new Versionstamp(str_repeat("\x00", 10), 0);
        self::assertTrue($vs->isComplete());
    }

    #[Test]
    public function isCompleteReturnsFalseForAllFfTrVersion(): void
    {
        $vs = new Versionstamp(str_repeat("\xFF", 10), 0);
        self::assertFalse($vs->isComplete());
    }

    #[Test]
    public function incompleteFactoryCreatesIncompleteVersionstamp(): void
    {
        $vs = Versionstamp::incomplete();
        self::assertFalse($vs->isComplete());
        self::assertSame(str_repeat("\xFF", 10), $vs->trVersion);
        self::assertSame(0, $vs->userVersion);
    }

    #[Test]
    public function incompleteFactoryAcceptsCustomUserVersion(): void
    {
        $vs = Versionstamp::incomplete(42);
        self::assertFalse($vs->isComplete());
        self::assertSame(42, $vs->userVersion);
    }

    #[Test]
    public function incompleteFactoryThrowsOnInvalidUserVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Versionstamp::incomplete(65536);
    }
}
