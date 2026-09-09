<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\BindingTester;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\Enum\MutationType;
use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\Future\Future;
use CrazyGoat\FoundationDB\KeySelector;
use CrazyGoat\FoundationDB\KeyUtil;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\ReadTransaction;
use CrazyGoat\FoundationDB\Transaction;
use CrazyGoat\FoundationDB\Tuple\Bytes;
use CrazyGoat\FoundationDB\Tuple\SingleFloat;
use CrazyGoat\FoundationDB\Tuple\Tuple;

/**
 * Stack machine implementing the upstream FoundationDB binding tester
 * protocol (see bindings/bindingtester/spec/bindingApiTester.md).
 *
 * Runs a single thread of instructions read from
 * prefix + tuple.pack((index,)) and mirrors the Python reference tester,
 * including its bytes-vs-string distinction (Tuple Bytes vs. PHP string).
 */
final class StackMachine
{
    /**
     * Transaction map shared by all stack machines ("threads"), keyed by
     * the current transaction name.
     *
     * @var array<string, Transaction>
     */
    private static array $transactions = [];

    public readonly Stack $stack;

    private string $transactionName;

    private int $lastVersion = 0;

    private readonly DirectoryExtension $directory;

    private const MUTATION_TYPES = [
        'ADD' => MutationType::Add,
        'BIT_AND' => MutationType::BitAnd,
        'BIT_OR' => MutationType::BitOr,
        'BIT_XOR' => MutationType::BitXor,
        'MAX' => MutationType::Max,
        'MIN' => MutationType::Min,
        'BYTE_MIN' => MutationType::ByteMin,
        'BYTE_MAX' => MutationType::ByteMax,
        'APPEND_IF_FITS' => MutationType::AppendIfFits,
        'SET_VERSIONSTAMPED_KEY' => MutationType::SetVersionstampedKey,
        'SET_VERSIONSTAMPED_VALUE' => MutationType::SetVersionstampedValue,
    ];

    /**
     * The instruction keys live under tuple.pack((prefix,)) where the prefix
     * is a *byte string* element (the upstream harness inserts instructions
     * with fdb.Subspace((bytes(instruction_prefix),))), so this is the
     * tuple-packed byte-string prefix, not the raw prefix.
     */
    private readonly string $packedPrefix;

    public function __construct(
        private readonly Database $db,
        string $prefix,
    ) {
        $this->packedPrefix = Tuple::pack([new Bytes($prefix)]);
        $this->stack = new Stack();
        $this->transactionName = $prefix;
        self::$transactions[$this->transactionName] ??= $this->db->createTransaction();
        $this->directory = new DirectoryExtension();
    }

    public function run(): void
    {
        [$rangeBegin, $rangeEnd] = [$this->packedPrefix . "\x00", $this->packedPrefix . "\xFF"];
        $instructions = $this->db->getRangeAll($rangeBegin, $rangeEnd);

        foreach ($instructions as $kv) {
            $instructionNumber = $this->instructionNumber($kv->key);
            $opTuple = Tuple::unpack($kv->value);
            $op = self::toRawString($opTuple[0]);

            $isDatabase = str_ends_with($op, '_DATABASE');
            $isSnapshot = false;
            if ($isDatabase) {
                $op = substr($op, 0, -9);
            } elseif (str_ends_with($op, '_TENANT')) {
                throw new \RuntimeException('Tenant operations are not supported by the PHP tester');
            } elseif (str_ends_with($op, '_SNAPSHOT')) {
                $op = substr($op, 0, -9);
                $isSnapshot = true;
            }

            try {
                $this->execute($op, $opTuple[1] ?? null, $isDatabase, $isSnapshot, $instructionNumber);
            } catch (FDBException $error) {
                $this->push($instructionNumber, self::errorString($error->fdbCode));
            }
        }
    }

    private function instructionNumber(string $key): int
    {
        $unpacked = Tuple::unpack($key, strlen($this->packedPrefix));

        if (!isset($unpacked[0]) || !is_int($unpacked[0])) {
            throw new \RuntimeException('Malformed instruction key under test prefix');
        }

        return $unpacked[0];
    }

    private function execute(
        string $op,
        mixed $argument,
        bool $isDatabase,
        bool $isSnapshot,
        int $instructionNumber,
    ): void {
        $stack = $this->stack;

        switch ($op) {
            case 'PUSH':
                $this->push($instructionNumber, $argument);
                return;

            case 'DUP':
                [$idx, $value] = $this->stack->peekWithIndex();
                $stack->push($idx, $value);
                return;

            case 'EMPTY_STACK':
                // Recreate the stack in place; python replaces the object.
                while (!$stack->isEmpty()) {
                    $stack->popWithIndex();
                }
                return;

            case 'SWAP':
                $stack->swap((int) $this->pop($stack));
                return;

            case 'POP':
                $this->pop($stack);
                return;

            case 'SUB':
                [$a, $b] = $this->popValues($stack, 2);
                $this->push($instructionNumber, self::subtract($a, $b));
                return;

            case 'CONCAT':
                [$a, $b] = $this->popValues($stack, 2);
                $this->push($instructionNumber, self::concat($a, $b));
                return;

            case 'WAIT_FUTURE':
                [$idx, $value] = $stack->popWithIndex();
                $stack->push($idx, $this->resolve($value));
                return;

            case 'NEW_TRANSACTION':
                self::$transactions[$this->transactionName] = $this->db->createTransaction();
                return;

            case 'USE_TRANSACTION':
                $this->transactionName = self::toRawString($this->pop($stack));
                if (!isset(self::$transactions[$this->transactionName])) {
                    self::$transactions[$this->transactionName] = $this->db->createTransaction();
                }
                return;

            case 'ON_ERROR':
                $code = (int) $this->pop($stack);
                $this->push($instructionNumber, $this->transaction()->onError($code));
                return;

            case 'GET':
                $key = self::toRawString($this->pop($stack));
                $value = $isDatabase
                    ? $this->db->get($key)
                    : $this->object($isSnapshot)->get($key)->await();

                $this->push($instructionNumber, $value === null ? new Bytes('RESULT_NOT_PRESENT') : new Bytes($value));
                return;

            case 'GET_ESTIMATED_RANGE_SIZE':
                [$begin, $end] = $this->popValues($stack, 2);
                if ($isDatabase) {
                    $this->db->getEstimatedRangeSizeBytes(self::toRawString($begin), self::toRawString($end));
                } else {
                    $this->object($isSnapshot)->getEstimatedRangeSizeBytes(
                        self::toRawString($begin),
                        self::toRawString($end),
                    )->await();
                }
                $this->push($instructionNumber, new Bytes('GOT_ESTIMATED_RANGE_SIZE'));
                return;

            case 'GET_RANGE_SPLIT_POINTS':
                [$begin, $end, $chunkSize] = $this->popValues($stack, 3);
                if ($isDatabase) {
                    $this->db->getRangeSplitPoints(self::toRawString($begin), self::toRawString($end), (int) $chunkSize);
                } else {
                    $this->object($isSnapshot)->getRangeSplitPoints(
                        self::toRawString($begin),
                        self::toRawString($end),
                        (int) $chunkSize,
                    )->await();
                }
                $this->push($instructionNumber, new Bytes('GOT_RANGE_SPLIT_POINTS'));
                return;

            case 'GET_KEY':
                [$key, $orEqual, $offset, $prefix] = $this->popValues($stack, 4);
                $selector = new KeySelector(
                    self::toRawString($key),
                    (bool) $orEqual,
                    (int) $offset,
                );
                $result = $isDatabase
                    ? $this->db->getKey($selector)
                    : $this->object($isSnapshot)->getKey($selector)->await();
                $prefixRaw = self::toRawString($prefix);

                if (str_starts_with($result, $prefixRaw)) {
                    $this->push($instructionNumber, new Bytes($result));
                } elseif (strcmp($result, $prefixRaw) < 0) {
                    $this->push($instructionNumber, $prefix);
                } else {
                    $strinc = KeyUtil::strinc($prefixRaw);
                    $this->push($instructionNumber, new Bytes($strinc ?? "\xFF"));
                }
                return;

            case 'GET_RANGE':
                [$begin, $end, $limit, $reverse, $mode] = $this->popValues($stack, 5);
                $options = new RangeOptions(
                    limit: (int) $limit === 0 ? null : (int) $limit,
                    reverse: (bool) $reverse,
                    mode: StreamingMode::from((int) $mode),
                );
                $rows = $isDatabase
                    ? $this->db->getRange(self::toRawString($begin), self::toRawString($end), $options)
                    : $this->object($isSnapshot)->getRange(
                        self::toRawString($begin),
                        self::toRawString($end),
                        $options,
                    )->toArray();
                $this->push($instructionNumber, new Bytes(self::packKeyValueList($rows)));
                return;

            case 'GET_RANGE_STARTS_WITH':
                [$prefix, $limit, $reverse, $mode] = $this->popValues($stack, 4);
                $options = new RangeOptions(
                    limit: (int) $limit === 0 ? null : (int) $limit,
                    reverse: (bool) $reverse,
                    mode: StreamingMode::from((int) $mode),
                );
                $rows = $isDatabase
                    ? $this->db->getRangeStartsWith(self::toRawString($prefix), $options)
                    : $this->object($isSnapshot)->getRangeStartsWith(self::toRawString($prefix), $options)->toArray();
                $this->push($instructionNumber, new Bytes(self::packKeyValueList($rows)));
                return;

            case 'GET_RANGE_SELECTOR':
                [
                    $beginKey,
                    $beginOrEqual,
                    $beginOffset,
                    $endKey,
                    $endOrEqual,
                    $endOffset,
                    $limit,
                    $reverse,
                    $mode,
                    $prefix,
                ] = $this->popValues($stack, 10);
                $beginSelector = new KeySelector(
                    self::toRawString($beginKey),
                    (bool) $beginOrEqual,
                    (int) $beginOffset,
                );
                $endSelector = new KeySelector(self::toRawString($endKey), (bool) $endOrEqual, (int) $endOffset);
                $options = new RangeOptions(
                    limit: (int) $limit === 0 ? null : (int) $limit,
                    reverse: (bool) $reverse,
                    mode: StreamingMode::from((int) $mode),
                );
                $prefixRaw = self::toRawString($prefix);

                $rows = $isDatabase
                    ? $this->db->getRange($beginSelector, $endSelector, $options)
                    : $this->object($isSnapshot)->getRange($beginSelector, $endSelector, $options)->toArray();

                $filtered = array_values(array_filter(
                    $rows,
                    static fn ($row): bool => str_starts_with($row->key, $prefixRaw),
                ));
                $this->push($instructionNumber, new Bytes(self::packKeyValueList($filtered)));
                return;

            case 'GET_READ_VERSION':
                $this->lastVersion = $this->object($isSnapshot)->getReadVersion()->await();
                $this->push($instructionNumber, new Bytes('GOT_READ_VERSION'));
                return;

            case 'GET_VERSIONSTAMP':
                $this->push($instructionNumber, $this->transaction()->getVersionstamp());
                return;

            case 'SET':
                $key = self::toRawString($this->pop($stack));
                $value = self::toRawString($this->pop($stack));
                if ($isDatabase) {
                    $this->db->set($key, $value);
                    $this->push($instructionNumber, new Bytes('RESULT_NOT_PRESENT'));
                } else {
                    $this->transaction()->set($key, $value);
                }
                return;

            case 'SET_READ_VERSION':
                $this->transaction()->setReadVersion($this->lastVersion);
                return;

            case 'CLEAR':
                $key = self::toRawString($this->pop($stack));
                if ($isDatabase) {
                    $this->db->clear($key);
                    $this->push($instructionNumber, new Bytes('RESULT_NOT_PRESENT'));
                } else {
                    $this->transaction()->clear($key);
                }
                return;

            case 'CLEAR_RANGE':
                [$begin, $end] = $this->popValues($stack, 2);
                if ($isDatabase) {
                    $this->db->clearRange(self::toRawString($begin), self::toRawString($end));
                    $this->push($instructionNumber, new Bytes('RESULT_NOT_PRESENT'));
                } else {
                    $this->transaction()->clearRange(self::toRawString($begin), self::toRawString($end));
                }
                return;

            case 'CLEAR_RANGE_STARTS_WITH':
                $prefix = self::toRawString($this->pop($stack));
                if ($isDatabase) {
                    $this->db->clearRangeStartsWith($prefix);
                    $this->push($instructionNumber, new Bytes('RESULT_NOT_PRESENT'));
                } else {
                    $this->transaction()->clearRangeStartsWith($prefix);
                }
                return;

            case 'ATOMIC_OP':
                $opType = self::toRawString($this->pop($stack));
                $key = self::toRawString($this->pop($stack));
                $value = self::toRawString($this->pop($stack));
                $mutationType = self::MUTATION_TYPES[$opType]
                    ?? throw new \RuntimeException('Unknown atomic op: ' . $opType);

                if ($isDatabase) {
                    $this->db->transact(static fn (Transaction $tr): mixed => $tr->atomicOp($mutationType, $key, $value));
                    $this->push($instructionNumber, new Bytes('RESULT_NOT_PRESENT'));
                } else {
                    $this->transaction()->atomicOp($mutationType, $key, $value);
                }
                return;

            case 'LOG_STACK':
                $prefix = self::toRawString($this->pop($stack));
                $this->logStack($prefix);
                return;

            case 'READ_CONFLICT_RANGE':
            case 'WRITE_CONFLICT_RANGE':
                [$begin, $end] = $this->popValues($stack, 2);
                if ($op === 'READ_CONFLICT_RANGE') {
                    $this->transaction()->addReadConflictRange(self::toRawString($begin), self::toRawString($end));
                } else {
                    $this->transaction()->addWriteConflictRange(self::toRawString($begin), self::toRawString($end));
                }
                $this->push($instructionNumber, new Bytes('SET_CONFLICT_RANGE'));
                return;

            case 'READ_CONFLICT_KEY':
            case 'WRITE_CONFLICT_KEY':
                $key = self::toRawString($this->pop($stack));
                if ($op === 'READ_CONFLICT_KEY') {
                    $this->transaction()->addReadConflictKey($key);
                } else {
                    $this->transaction()->addWriteConflictKey($key);
                }
                $this->push($instructionNumber, new Bytes('SET_CONFLICT_KEY'));
                return;

            case 'DISABLE_WRITE_CONFLICT':
                $this->transaction()->options()->setNextWriteNoWriteConflictRange();
                return;

            case 'COMMIT':
                $this->push($instructionNumber, $this->transaction()->commit());
                return;

            case 'RESET':
                $this->transaction()->reset();
                return;

            case 'CANCEL':
                $this->transaction()->cancel();
                return;

            case 'GET_COMMITTED_VERSION':
                $this->lastVersion = $this->transaction()->getCommittedVersion();
                $this->push($instructionNumber, new Bytes('GOT_COMMITTED_VERSION'));
                return;

            case 'GET_APPROXIMATE_SIZE':
                $this->transaction()->getApproximateSize()->await();
                $this->push($instructionNumber, new Bytes('GOT_APPROXIMATE_SIZE'));
                return;

            case 'TUPLE_PACK':
                $count = (int) $this->pop($stack);
                $this->push($instructionNumber, new Bytes(Tuple::pack($this->popValues($stack, $count))));
                return;

            case 'TUPLE_PACK_WITH_VERSIONSTAMP':
                $prefix = $this->pop($stack);
                $count = (int) $this->pop($stack);
                $items = $this->popValues($stack, $count);

                if (!Tuple::hasIncompleteVersionstamp($items)) {
                    $this->push($instructionNumber, new Bytes('ERROR: NONE'));
                    return;
                }

                try {
                    $packed = Tuple::packWithVersionstamp($items, self::toRawString($prefix));
                    $this->push($instructionNumber, new Bytes('OK'));
                    $this->push($instructionNumber, new Bytes($packed));
                } catch (\InvalidArgumentException $error) {
                    if (str_contains($error->getMessage(), 'found 0')) {
                        $this->push($instructionNumber, new Bytes('ERROR: NONE'));
                    } else {
                        $this->push($instructionNumber, new Bytes('ERROR: MULTIPLE'));
                    }
                }
                return;

            case 'TUPLE_UNPACK':
                $packed = $this->pop($stack);
                foreach (Tuple::unpack(self::toRawString($packed)) as $element) {
                    $this->push($instructionNumber, new Bytes(Tuple::pack([$element])));
                }
                return;

            case 'TUPLE_SORT':
                $count = (int) $this->pop($stack);
                $items = $this->popValues($stack, $count);
                $unpacked = array_map(
                    static fn ($item): array => Tuple::unpack(self::toRawString($item)),
                    $items,
                );
                usort($unpacked, static fn (array $a, array $b): int => strcmp(Tuple::pack($a), Tuple::pack($b)));
                foreach ($unpacked as $tuple) {
                    $this->push($instructionNumber, new Bytes(Tuple::pack($tuple)));
                }
                return;

            case 'TUPLE_RANGE':
                $count = (int) $this->pop($stack);
                [$begin, $end] = Tuple::range($this->popValues($stack, $count));
                $this->push($instructionNumber, new Bytes($begin));
                $this->push($instructionNumber, new Bytes($end));
                return;

            case 'ENCODE_FLOAT':
                $bytes = self::toRawString($this->pop($stack));
                $decoded = unpack('G', $bytes);
                if ($decoded === false) {
                    throw new \RuntimeException('ENCODE_FLOAT: invalid 4-byte input');
                }
                $this->push($instructionNumber, self::floatToPush($decoded[1]));
                return;

            case 'ENCODE_DOUBLE':
                $bytes = self::toRawString($this->pop($stack));
                $decoded = unpack('E', $bytes);
                if ($decoded === false) {
                    throw new \RuntimeException('ENCODE_DOUBLE: invalid 8-byte input');
                }
                $this->push($instructionNumber, $decoded[1]);
                return;

            case 'DECODE_FLOAT':
                $value = $this->pop($stack);
                $float = $value instanceof SingleFloat ? $value->value : (float) $value;
                $this->push($instructionNumber, new Bytes(pack('G', $float)));
                return;

            case 'DECODE_DOUBLE':
                $value = $this->pop($stack);
                $this->push($instructionNumber, new Bytes(pack('E', (float) $value)));
                return;

            case 'START_THREAD':
                $threadPrefix = self::toRawString($this->pop($stack));
                $machine = new self($this->db, $threadPrefix);
                $machine->run();
                return;

            case 'WAIT_EMPTY':
                $prefix = self::toRawString($this->pop($stack));
                $this->db->transact(function (Transaction $tr) use ($prefix): void {
                    $rows = $tr->getRangeStartsWith($prefix, new RangeOptions(limit: 1))->toArray();
                    if ($rows !== []) {
                        throw new FDBException(1020);
                    }
                });
                $this->push($instructionNumber, new Bytes('WAITED_FOR_EMPTY'));
                return;

            case 'UNIT_TESTS':
                UnitTests::run($this->db);
                return;

            default:
                if (str_starts_with($op, 'DIRECTORY_')) {
                    $this->directory->processInstruction(
                        $this,
                        $op,
                        $this->transaction(),
                        $instructionNumber,
                    );
                    return;
                }

                throw new \RuntimeException('Unknown op: ' . $op);
        }
    }

    // ------------------------------------------------------------------
    // Stack helpers (futures are resolved lazily on pop, like the Python
    // reference tester).
    // ------------------------------------------------------------------

    public function push(int $instructionNumber, mixed $value): void
    {
        $this->stack->push($instructionNumber, $value);
    }

    /**
     * @return list<mixed>
     */
    public function popValues(Stack $stack, int $count): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->pop($stack);
        }

        return $values;
    }

    public function pop(Stack $stack): mixed
    {
        return $this->resolve($stack->popWithIndex()[1]);
    }

    /**
     * Pops a single tuple (an item holding the tuple length, then the
     * elements), mirroring the upstream directory tester's `pop_tuples`.
     *
     * @return list<mixed>
     */
    public function popTuple(Stack $stack): array
    {
        $length = (int) $this->pop($stack);

        return $this->popValues($stack, $length);
    }

    /**
     * Pops `$count` tuples.
     *
     * @return list<list<mixed>>
     */
    public function popTuples(Stack $stack, int $count = 1): array
    {
        $tuples = [];
        for ($i = 0; $i < $count; $i++) {
            $tuples[] = $this->popTuple($stack);
        }

        return $tuples;
    }

    private function resolve(mixed $value): mixed
    {
        if (!$value instanceof Future) {
            return $value;
        }

        try {
            $result = $value->await();
        } catch (FDBException $error) {
            return self::errorString($error->fdbCode);
        }

        if ($result === null) {
            return new Bytes('RESULT_NOT_PRESENT');
        }

        if (is_string($result)) {
            return new Bytes($result);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Value helpers
    // ------------------------------------------------------------------

    /**
     * Converts a stack value to a raw PHP string. Bytes objects are
     * unwrapped; strings pass through unchanged (mirroring the Python
     * bytes/str distinction at API boundaries).
     */
    public static function toRawString(mixed $value): string
    {
        if ($value instanceof Bytes) {
            return $value->data;
        }

        if (is_string($value)) {
            return $value;
        }

        throw new \RuntimeException('Expected a byte string or string value, got ' . get_debug_type($value));
    }

    /**
     * Packs a list of PHP strings as a tuple (string elements), used for
     * directory listing results.
     *
     * @param list<string> $items
     */
    public static function packList(array $items): string
    {
        return Tuple::pack($items);
    }

    /**
     * Packs a list of key/value pairs as [k1, v1, k2, v2, ...]. Database
     * keys and values are byte strings, so they are packed as bytes.
     *
     * @param list<\CrazyGoat\FoundationDB\KeyValue> $rows
     */
    private static function packKeyValueList(array $rows): string
    {
        $elements = [];
        foreach ($rows as $row) {
            $elements[] = new Bytes($row->key);
            $elements[] = new Bytes($row->value);
        }

        return Tuple::pack($elements);
    }

    public static function errorString(int $errorCode): Bytes
    {
        return new Bytes(Tuple::pack([new Bytes('ERROR'), new Bytes((string) $errorCode)]));
    }

    private static function subtract(mixed $a, mixed $b): int|\GMP
    {
        if ($a instanceof \GMP || $b instanceof \GMP) {
            return gmp_sub(self::toGmp($a), self::toGmp($b));
        }

        if (!is_int($a) || !is_int($b)) {
            throw new \RuntimeException('SUB operands must be integers, got '
                . get_debug_type($a) . ' and ' . get_debug_type($b));
        }

        return $a - $b;
    }

    private static function toGmp(mixed $value): \GMP
    {
        if ($value instanceof \GMP) {
            return $value;
        }

        if (is_int($value)) {
            return gmp_init($value);
        }

        throw new \RuntimeException('Expected integer value, got ' . get_debug_type($value));
    }

    private static function concat(mixed $a, mixed $b): Bytes|string
    {
        if ($a instanceof Bytes && $b instanceof Bytes) {
            return new Bytes($a->data . $b->data);
        }

        if (is_string($a) && is_string($b)) {
            return $a . $b;
        }

        throw new \RuntimeException('CONCAT operands must both be bytes or both strings');
    }

    private static function floatToPush(float $value): SingleFloat
    {
        // Mirror the Python tester: integral (non-special) values are
        // converted to integers before being wrapped in a SingleFloat.
        if (!is_nan($value) && !is_infinite($value) && $value != -0.0 && floor($value) === $value) {
            $value = (float) (int) $value;
        }

        return new SingleFloat($value);
    }

    // ------------------------------------------------------------------
    // Transaction helpers
    // ------------------------------------------------------------------

    private function transaction(): Transaction
    {
        return self::$transactions[$this->transactionName]
            ??= $this->db->createTransaction();
    }

    private function object(bool $isSnapshot): ReadTransaction
    {
        if ($isSnapshot) {
            return $this->transaction()->snapshot();
        }

        return $this->transaction();
    }

    // ------------------------------------------------------------------
    // LOG_STACK
    // ------------------------------------------------------------------

    private function logStack(string $prefix): void
    {
        $entries = $this->stack->drain();

        foreach (array_chunk($entries, 100) as $batch) {
            $this->db->transact(static function (Transaction $tr) use ($prefix, $batch): void {
                foreach ($batch as [$stackIndex, $instructionNumber, $value]) {
                    $key = $prefix . Tuple::pack([$stackIndex, $instructionNumber]);
                    $packed = Tuple::pack([$value]);
                    $tr->set($key, strlen($packed) > 40000 ? substr($packed, 0, 40000) : $packed);
                }
            });
        }
    }
}
