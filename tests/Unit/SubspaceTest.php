<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\KeyConvertible;
use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Tuple\Tuple;
use CrazyGoat\FoundationDB\Tuple\Versionstamp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubspaceTest extends TestCase
{
    #[Test]
    public function constructorWithTuplePrefix(): void
    {
        $sub = new Subspace(['users']);

        self::assertSame(Tuple::pack(['users']), $sub->rawPrefix);
    }

    #[Test]
    public function constructorWithRawPrefix(): void
    {
        $sub = new Subspace(rawPrefix: 'raw_');

        self::assertSame('raw_', $sub->rawPrefix);
    }

    #[Test]
    public function constructorWithBothPrefixes(): void
    {
        $sub = new Subspace(['users'], 'raw_');

        self::assertSame('raw_' . Tuple::pack(['users']), $sub->rawPrefix);
    }

    #[Test]
    public function constructorWithEmptyTuple(): void
    {
        $sub = new Subspace();

        self::assertSame('', $sub->rawPrefix);
    }

    #[Test]
    public function constructorWithMultiElementTuple(): void
    {
        $sub = new Subspace(['app', 'users', 1]);

        self::assertSame(Tuple::pack(['app', 'users', 1]), $sub->rawPrefix);
    }

    #[Test]
    public function keyReturnsRawPrefix(): void
    {
        $sub = new Subspace(['users']);

        self::assertSame($sub->rawPrefix, $sub->key());
    }

    #[Test]
    public function packWithEmptyTuple(): void
    {
        $sub = new Subspace(['users']);

        self::assertSame($sub->rawPrefix, $sub->pack());
    }

    #[Test]
    public function packWithElements(): void
    {
        $sub = new Subspace(['users']);
        $packed = $sub->pack(['alice', 42]);

        self::assertSame(
            Tuple::pack(['users']) . Tuple::pack(['alice', 42]),
            $packed,
        );
    }

    #[Test]
    public function packWithSingleElement(): void
    {
        $sub = new Subspace(['users']);
        $packed = $sub->pack(['alice']);

        self::assertSame(
            Tuple::pack(['users']) . Tuple::pack(['alice']),
            $packed,
        );
    }

    #[Test]
    public function unpackRoundtrip(): void
    {
        $sub = new Subspace(['users']);
        $packed = $sub->pack(['alice', 42]);
        $unpacked = $sub->unpack($packed);

        self::assertSame('alice', $unpacked[0]);
        self::assertSame(42, $unpacked[1]);
    }

    #[Test]
    public function unpackEmptyTuple(): void
    {
        $sub = new Subspace(['users']);
        $packed = $sub->pack();
        $unpacked = $sub->unpack($packed);

        self::assertSame([], $unpacked);
    }

    #[Test]
    public function unpackWithWrongPrefixThrows(): void
    {
        $sub = new Subspace(['users']);
        $wrongKey = Tuple::pack(['orders', 'item1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key does not start with the subspace prefix');

        $sub->unpack($wrongKey);
    }

    #[Test]
    public function rangeWithEmptyTuple(): void
    {
        $sub = new Subspace(['users']);
        [$begin, $end] = $sub->range();

        self::assertSame($sub->rawPrefix . "\x00", $begin);
        self::assertSame($sub->rawPrefix . "\xFF", $end);
    }

    #[Test]
    public function rangeWithElements(): void
    {
        $sub = new Subspace(['users']);
        [$begin, $end] = $sub->range(['alice']);

        $packed = $sub->pack(['alice']);
        self::assertSame($packed . "\x00", $begin);
        self::assertSame($packed . "\xFF", $end);
    }

    #[Test]
    public function containsWithMatchingKey(): void
    {
        $sub = new Subspace(['users']);
        $key = $sub->pack(['alice']);

        self::assertTrue($sub->contains($key));
    }

    #[Test]
    public function containsWithNonMatchingKey(): void
    {
        $sub = new Subspace(['users']);
        $key = Tuple::pack(['orders', 'item1']);

        self::assertFalse($sub->contains($key));
    }

    #[Test]
    public function containsWithExactPrefix(): void
    {
        $sub = new Subspace(['users']);

        self::assertTrue($sub->contains($sub->rawPrefix));
    }

    #[Test]
    public function containsWithEmptySubspace(): void
    {
        $sub = new Subspace();

        self::assertTrue($sub->contains('anything'));
    }

    #[Test]
    public function subspaceCreatesChild(): void
    {
        $parent = new Subspace(['app']);
        $child = $parent->subspace('users');

        self::assertSame(
            Tuple::pack(['app']) . Tuple::pack(['users']),
            $child->rawPrefix,
        );
    }

    #[Test]
    public function subspaceWithIntegerElement(): void
    {
        $parent = new Subspace(['app']);
        $child = $parent->subspace(42);

        self::assertSame(
            Tuple::pack(['app']) . Tuple::pack([42]),
            $child->rawPrefix,
        );
    }

    #[Test]
    public function nestedSubspaces(): void
    {
        $root = new Subspace(['app']);
        $users = $root->subspace('users');
        $alice = $users->subspace('alice');

        self::assertSame(
            Tuple::pack(['app']) . Tuple::pack(['users']) . Tuple::pack(['alice']),
            $alice->rawPrefix,
        );
    }

    #[Test]
    public function nestedSubspacePackUnpack(): void
    {
        $root = new Subspace(['app']);
        $users = $root->subspace('users');

        $packed = $users->pack(['alice', 'email']);
        $unpacked = $users->unpack($packed);

        self::assertSame('alice', $unpacked[0]);
        self::assertSame('email', $unpacked[1]);
    }

    #[Test]
    public function nestedSubspaceContains(): void
    {
        $root = new Subspace(['app']);
        $users = $root->subspace('users');
        $orders = $root->subspace('orders');

        $userKey = $users->pack(['alice']);
        $orderKey = $orders->pack(['order1']);

        self::assertTrue($users->contains($userKey));
        self::assertFalse($users->contains($orderKey));
        self::assertTrue($orders->contains($orderKey));
        self::assertFalse($orders->contains($userKey));
        self::assertTrue($root->contains($userKey));
        self::assertTrue($root->contains($orderKey));
    }

    #[Test]
    public function asFoundationDbKeyReturnsPrefix(): void
    {
        $sub = new Subspace(['users']);

        self::assertSame($sub->key(), $sub->asFoundationDbKey());
    }

    #[Test]
    public function implementsKeyConvertible(): void
    {
        $sub = new Subspace(['users']);

        self::assertInstanceOf(KeyConvertible::class, $sub);
    }

    #[Test]
    public function subspaceWithRawPrefixAndTuple(): void
    {
        $sub = new Subspace(['data'], "\xFE");
        $packed = $sub->pack(['key1']);

        self::assertTrue(str_starts_with($packed, "\xFE"));
        self::assertSame(
            "\xFE" . Tuple::pack(['data']) . Tuple::pack(['key1']),
            $packed,
        );
    }

    #[Test]
    public function subspaceRangeUsedForIteration(): void
    {
        $sub = new Subspace(['users']);
        [$begin, $end] = $sub->range();

        $key1 = $sub->pack(['alice']);
        $key2 = $sub->pack(['bob']);

        self::assertGreaterThan($begin, $key1);
        self::assertLessThan($end, $key1);
        self::assertGreaterThan($begin, $key2);
        self::assertLessThan($end, $key2);
    }

    #[Test]
    public function subspacePreservesKeyOrdering(): void
    {
        $sub = new Subspace(['users']);

        $key1 = $sub->pack(['alice']);
        $key2 = $sub->pack(['bob']);
        $key3 = $sub->pack(['charlie']);

        self::assertLessThan($key2, $key1);
        self::assertLessThan($key3, $key2);
    }

    #[Test]
    public function subspaceWithNullElement(): void
    {
        $sub = new Subspace(['data']);
        $child = $sub->subspace(null);

        $packed = $child->pack(['value']);
        $unpacked = $child->unpack($packed);

        self::assertSame('value', $unpacked[0]);
    }

    #[Test]
    public function subspaceWithBoolElement(): void
    {
        $sub = new Subspace(['flags']);
        $child = $sub->subspace(true);

        $packed = $child->pack(['enabled']);
        $unpacked = $child->unpack($packed);

        self::assertSame('enabled', $unpacked[0]);
    }

    #[Test]
    public function emptySubspacePacksJustTuple(): void
    {
        $sub = new Subspace();
        $packed = $sub->pack(['hello', 'world']);

        self::assertSame(Tuple::pack(['hello', 'world']), $packed);
    }

    #[Test]
    public function emptySubspaceUnpacksFullKey(): void
    {
        $sub = new Subspace();
        $packed = Tuple::pack(['hello', 'world']);
        $unpacked = $sub->unpack($packed);

        self::assertSame('hello', $unpacked[0]);
        self::assertSame('world', $unpacked[1]);
    }

    #[Test]
    public function subspaceChildReturnsNewInstance(): void
    {
        $parent = new Subspace(['app']);
        $child1 = $parent->subspace('users');
        $child2 = $parent->subspace('users');

        self::assertNotSame($child1, $child2);
        self::assertSame($child1->rawPrefix, $child2->rawPrefix);
    }

    #[Test]
    public function subspaceDoesNotMutateParent(): void
    {
        $parent = new Subspace(['app']);
        $originalPrefix = $parent->rawPrefix;

        $parent->subspace('users');
        $parent->pack(['data']);

        self::assertSame($originalPrefix, $parent->rawPrefix);
    }

    #[Test]
    public function packWithVersionstampPrependsPrefix(): void
    {
        $sub = new Subspace(['users']);
        $packed = $sub->packWithVersionstamp([Versionstamp::incomplete()]);

        self::assertTrue(str_starts_with($packed, $sub->rawPrefix));
    }

    #[Test]
    public function packWithVersionstampAdjustsOffset(): void
    {
        $sub = new Subspace(['users']);
        $prefixLength = strlen($sub->rawPrefix);

        $withoutPrefix = Tuple::packWithVersionstamp([Versionstamp::incomplete()]);
        $originalOffsetData = unpack('V', substr($withoutPrefix, -4));
        self::assertIsArray($originalOffsetData);
        /** @var int $originalOffset */
        $originalOffset = $originalOffsetData[1];

        $withPrefix = $sub->packWithVersionstamp([Versionstamp::incomplete()]);
        $adjustedOffsetData = unpack('V', substr($withPrefix, -4));
        self::assertIsArray($adjustedOffsetData);
        /** @var int $adjustedOffset */
        $adjustedOffset = $adjustedOffsetData[1];

        self::assertSame($originalOffset + $prefixLength, $adjustedOffset);
    }

    #[Test]
    public function packWithVersionstampEmptySubspaceMatchesTuplePack(): void
    {
        $sub = new Subspace();
        $fromSubspace = $sub->packWithVersionstamp([Versionstamp::incomplete()]);
        $fromTuple = Tuple::packWithVersionstamp([Versionstamp::incomplete()]);

        self::assertSame($fromTuple, $fromSubspace);
    }

    #[Test]
    public function packWithVersionstampWithMultipleElements(): void
    {
        $sub = new Subspace(['app']);
        $packed = $sub->packWithVersionstamp(['key1', Versionstamp::incomplete()]);

        self::assertTrue(str_starts_with($packed, $sub->rawPrefix));

        $offsetData = unpack('V', substr($packed, -4));
        self::assertIsArray($offsetData);
        /** @var int $offset */
        $offset = $offsetData[1];

        self::assertSame(chr(0x33), $packed[$offset - 1]);
    }

    #[Test]
    public function packWithVersionstampWithRawPrefix(): void
    {
        $sub = new Subspace([], "\xFE");
        $packed = $sub->packWithVersionstamp([Versionstamp::incomplete()]);

        self::assertTrue(str_starts_with($packed, "\xFE"));

        $offsetData = unpack('V', substr($packed, -4));
        self::assertIsArray($offsetData);
        /** @var int $offset */
        $offset = $offsetData[1];

        self::assertSame(chr(0x33), $packed[$offset - 1]);
    }

    #[Test]
    public function packWithVersionstampNoVersionstampThrows(): void
    {
        $sub = new Subspace(['users']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('packWithVersionstamp requires exactly one Versionstamp');

        $sub->packWithVersionstamp(['no_versionstamp']);
    }
}
