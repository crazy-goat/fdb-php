<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

final class Locality
{
    private const KEY_SERVERS_PREFIX = "\xFF/keyServers/";

    /**
     * @return list<string>
     */
    public static function getBoundaryKeys(Database $db, string $begin, string $end): array
    {
        $boundaries = [];
        $currentBegin = $begin;

        while ($currentBegin < $end) {
            $tr = $db->createTransaction();
            $tr->options()->setReadSystemKeys();
            $tr->options()->setLockAware();

            $lastBegin = $currentBegin;

            try {
                $rangeResult = $tr->snapshot()->getRange(
                    self::KEY_SERVERS_PREFIX . $currentBegin,
                    self::KEY_SERVERS_PREFIX . $end,
                );

                foreach ($rangeResult as $kv) {
                    $key = substr($kv->key, strlen(self::KEY_SERVERS_PREFIX));
                    $boundaries[] = $key;
                    $currentBegin = $key . "\x00";
                }

                $currentBegin = $end;
            } catch (FDBException $e) {
                if ($e->fdbCode === 1007 && $currentBegin !== $lastBegin) {
                    continue;
                }

                $tr->onError($e->fdbCode)->await();
            }
        }

        return $boundaries;
    }
}
