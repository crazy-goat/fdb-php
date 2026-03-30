<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI;
use FFI\CData;

final readonly class Tenant
{
    public function __construct(
        private CData $tpointer,
        private Database $db,
        private NativeClient $client,
    ) {
    }

    public function createTransaction(): Transaction
    {
        $trPointer = $this->client->fdb->new('FDBTransaction*');
        $this->client->checkError(
            $this->client->fdb->fdb_tenant_create_transaction($this->tpointer, FFI::addr($trPointer)),
        );

        return new Transaction($trPointer, $this->db, $this->client);
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_tenant_destroy($this->tpointer);
    }
}
