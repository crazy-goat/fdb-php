<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Administrative operations for FoundationDB cluster management.
 *
 * This class provides methods for cluster administration that require
 * elevated privileges. These operations are separate from normal
 * database operations (CRUD) which are handled by the Database class.
 *
 * All admin operations use FoundationDB Special Keys (keys starting with \xff\xff)
 * which provide a programmatic interface to administrative functions without
 * requiring external CLI tools.
 *
 * ## Input validation contract (fix for #43)
 *
 * Every public admin method validates its caller-supplied inputs at the PHP
 * trust boundary and throws `\InvalidArgumentException` on rejection. Inputs
 * that would otherwise be silently spliced into a privileged Special Key —
 * tenant names, server addresses, dcId, configure tokens — are checked
 * against a small allow-list (printable ASCII subset) and a hard length
 * bound before the transaction begins, so the failure surfaces at the call
 * site instead of as an opaque commit-time error and so a malformed input
 * cannot unexpectedly address a different Special Key path:
 *
 * | Method            | Validated input                              | Allow-list                       | Max length |
 * |-------------------|----------------------------------------------|----------------------------------|------------|
 * | createTenant      | tenant name                                  | `[A-Za-z0-9._-]`, no leading ./_ | 256 bytes  |
 * | deleteTenant      | tenant name                                  | (same as createTenant)           | 256 bytes  |
 * | excludeServer     | server address (IP:port)                     | `[A-Za-z0-9._:-]`                | 256 bytes  |
 * | includeServer     | server address (IP:port)                     | (same as excludeServer)          | 256 bytes  |
 * | configure         | whitespace-split tokens, ≥1 token            | `[A-Za-z0-9_-]` per token        | 64 bytes   |
 * | forceRecovery     | datacenter identifier                        | `[A-Za-z0-9_-]`                  | 64 bytes   |
 *
 * The allow-list deliberately excludes every byte below 0x20, 0x7F (DEL),
 * 0x80–0xFF, and `"/"`, so a tenant name or address containing a slash,
 * any control byte, a high byte, or whitespace cannot address a different
 * Special Key sub-path than the one intended.
 */
final readonly class AdminClient
{
    /** Special key prefix for tenant management */
    private const TENANT_MAP_PREFIX = "\xff\xff/management/tenant/map/";

    /**
     * Maximum byte length for a tenant name / server address input.
     *
     * The FDB key size limit (10,000 bytes) is the outer bound; 256 leaves
     * plenty of headroom for the Special Key prefix overhead and is far in
     * excess of any reasonable tenant/address name in practice.
     */
    private const MAX_LABEL_LENGTH = 256;

    /**
     * Maximum byte length for a single configure() / forceRecovery() token.
     */
    private const MAX_TOKEN_LENGTH = 64;

    /**
     * Allow-list for tenant names. Printable ASCII letters/digits plus
     * `.`, `_`, `-`. Excludes `/`, control bytes (0x00–0x1F), space (0x20),
     * DEL (0x7F), and all high bytes (0x80–0xFF) so a tenant name cannot
     * unexpectedly address a different management sub-key.
     *
     * Anchored: the entire string must match.
     */
    private const TENANT_NAME_REGEX = '/\A[A-Za-z0-9._-]{1,256}\z/';

    /** Tenant names may not begin with `.`, `_`, or `-`. */
    private const TENANT_NAME_LEADING_REGEX = '/\A[A-Za-z0-9]/';

    /**
     * Allow-list for server addresses (host:port). Same characters as
     * `TENANT_NAME_REGEX` but additionally permits `:` for the port
     * separator.
     */
    private const ADDRESS_REGEX = '/\A[A-Za-z0-9._:-]{1,256}\z/';

    /**
     * Allow-list for a single whitespace-delimited token in `configure()`
     * and for a dcId in `forceRecovery()`. Letters, digits, underscore,
     * dash. More restrictive than tenant/address because these values
     * flow into multiple Special Key paths.
     */
    private const TOKEN_REGEX = '/\A[A-Za-z0-9_-]{1,64}\z/';

    public function __construct(
        private Database $database,
        private NativeClient $client
    ) {
    }

    /**
     * Create a new tenant in the cluster.
     *
     * Uses special key: \xff\xff/management/tenant/map/<tenant>
     *
     * @param string $name The name of the tenant to create. Must match
     *                     `[A-Za-z0-9._-]{1,256}` and start with an
     *                     alphanumeric character.
     *
     * @throws \InvalidArgumentException If `$name` contains disallowed
     *                                    characters, starts with `.`/`_`/`-`,
     *                                    or is empty.
     * @throws FDBException              If tenant creation fails.
     */
    public function createTenant(string $name): void
    {
        $this->validateTenantName($name, 'createTenant');

        $this->database->transact(function (Transaction $tr) use ($name): void {
            // Enable writes to special key space
            $tr->options()->setSpecialKeySpaceEnableWrites();

            // Validate up front: the prefix overhead + this name must still fit
            // within the FDB key size limit. Asserting before set() guarantees the
            // failure surfaces inside this call site rather than as an opaque
            // commit-time error.
            $key = self::TENANT_MAP_PREFIX . $name;
            KeyValueLimits::assertValidKey($key);

            $tr->set($key, '{}'); // Empty JSON object as value
        });
    }

    /**
     * Delete a tenant from the cluster.
     *
     * Uses special key: \xff\xff/management/tenant/map/<tenant>
     *
     * @param string $name The name of the tenant to delete. Same validation
     *                     contract as {@see self::createTenant()}.
     *
     * @throws \InvalidArgumentException If `$name` is invalid.
     * @throws FDBException              If tenant deletion fails.
     */
    public function deleteTenant(string $name): void
    {
        $this->validateTenantName($name, 'deleteTenant');

        $this->database->transact(function (Transaction $tr) use ($name): void {
            // Enable writes to special key space
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = self::TENANT_MAP_PREFIX . $name;
            KeyValueLimits::assertValidKey($key);

            $tr->clear($key);
        });
    }

    /**
     * List all tenants in the cluster.
     *
     * Uses special key range: \xff\xff/management/tenant/map/
     *
     * @return list<string> List of tenant names
     * @throws FDBException If listing fails
     */
    public function listTenants(): array
    {
        $end = KeyUtil::strinc(self::TENANT_MAP_PREFIX);
        if ($end === null) {
            throw new \RuntimeException(
                'Cannot compute tenant range end key: prefix is empty or entirely 0xFF bytes'
            );
        }

        /** @var list<KeyValue> $results */
        $results = $this->database->transact(function (Transaction $tr) use ($end): array {
            $begin = self::TENANT_MAP_PREFIX;

            return $tr->getRange($begin, $end)->toArray();
        });

        $tenants = [];
        foreach ($results as $kv) {
            // Extract tenant name from key (remove prefix)
            $tenantName = substr($kv->key, strlen(self::TENANT_MAP_PREFIX));
            if ($tenantName !== '') {
                $tenants[] = $tenantName;
            }
        }

        return $tenants;
    }

    /**
     * Reboots a FoundationDB worker process. The address is validated
     * against {@see self::ADDRESS_REGEX} before the FFI call.
     *
     * @param string $address The network address of the worker to reboot (e.g., "127.0.0.1:4500")
     * @param bool $checkFile If true, checks that a file exists at the specified path before rebooting
     * @param int $suspendDuration Duration in seconds to suspend the process (0 for immediate restart)
     *
     * @throws \InvalidArgumentException If `$address` contains disallowed characters or is empty.
     * @throws RebootWorkerException     If the reboot operation fails.
     *
     * @warning Do not close the Database immediately after calling this method, as the operation
     *          may still be in progress. Allow sufficient time for the operation to complete.
     */
    public function rebootWorker(string $address, bool $checkFile = false, int $suspendDuration = 0): void
    {
        $this->validateAddress($address);

        $future = new Future\FutureInt64(
            // @phpstan-ignore method.notFound
            $this->client->fdb->fdb_database_reboot_worker(
                $this->database->getDatabasePointer(),
                $address,
                strlen($address),
                $checkFile ? 1 : 0,
                $suspendDuration,
            ),
            $this->client,
        );

        $result = $future->await();

        if ($result === 0) {
            throw new RebootWorkerException($address);
        }
    }

    /**
     * Configure the database.
     *
     * Uses special keys to change database configuration.
     *
     * Accepts a whitespace-separated token sequence; the first token is the
     * redundancy level (e.g. `single`, `double`, `triple`) and the second
     * is the storage engine (e.g. `ssd`, `memory`, `hdd`). With one token,
     * the storage engine defaults to `ssd` (matching FoundationDB's own
     * behaviour). Each token must match `[A-Za-z0-9_-]{1,64}` and the
     * total count must be either 1 or 2; any other shape is rejected
     * here so a malformed string cannot write into a wrong Special Key.
     *
     * @param string $configuration Configuration string (e.g., "double ssd")
     *
     * @throws \InvalidArgumentException If the configuration string is empty,
     *                                    contains too few/many tokens, or any
     *                                    token is malformed.
     * @throws FDBException              If configuration fails.
     */
    public function configure(string $configuration): void
    {
        [$redundancy, $storage] = $this->parseConfiguration($configuration);

        $this->database->transact(function (Transaction $tr) use ($redundancy, $storage): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            // Set configuration via special keys
            $tr->set("\xff\xff/configuration/redundancy", $redundancy);
            $tr->set("\xff\xff/configuration/storage", $storage);
        });
    }

    /**
     * Exclude a server from the database.
     *
     * Uses special key: \xff\xff/management/excluded/<address>
     *
     * @param string $address Server address (e.g., "127.0.0.1:4500"). Must
     *                        match `[A-Za-z0-9._:-]{1,256}`.
     *
     * @throws \InvalidArgumentException If `$address` is invalid.
     * @throws FDBException              If exclusion fails.
     */
    public function excludeServer(string $address): void
    {
        $this->validateAddress($address);

        $this->database->transact(function (Transaction $tr) use ($address): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = "\xff\xff/management/excluded/{$address}";

            // Tenant/server identifiers flow into a special key with a fixed
            // prefix overhead; validate up front so pathological inputs fail here
            // instead of as an opaque commit-time error.
            KeyValueLimits::assertValidKey($key);

            $tr->set($key, '');
        });
    }

    /**
     * Include a previously excluded server back into the database.
     *
     * Uses special key: \xff\xff/management/excluded/<address>
     *
     * @param string $address Server address (e.g., "127.0.0.1:4500"). Same
     *                        validation contract as {@see self::excludeServer()}.
     *
     * @throws \InvalidArgumentException If `$address` is invalid.
     * @throws FDBException              If inclusion fails.
     */
    public function includeServer(string $address): void
    {
        $this->validateAddress($address);

        $this->database->transact(function (Transaction $tr) use ($address): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = "\xff\xff/management/excluded/{$address}";
            KeyValueLimits::assertValidKey($key);

            $tr->clear($key);
        });
    }

    /**
     * Run a consistency check on the database.
     *
     * Uses special key: \xff\xff/management/consistency_check_suspended
     *
     * @return bool True if database is consistent
     * @throws FDBException If check fails
     */
    public function consistencyCheck(): bool
    {
        // Check if consistency check is suspended
        $result = $this->database->get("\xff\xff/management/consistency_check_suspended");

        // If key exists, consistency check is suspended (not running)
        return $result === null;
    }

    /**
     * Get detailed cluster status.
     *
     * Uses special key: \xff\xff/status/json
     *
     * @return array<string, mixed> Structured cluster status information
     * @throws FDBException If status retrieval fails
     */
    public function getClusterStatus(): array
    {
        $json = $this->database->get("\xff\xff/status/json");

        if ($json === null) {
            throw new \RuntimeException('Failed to retrieve cluster status');
        }

        return $this->decodeClusterStatusJson($json);
    }

    /**
     * Decode and validate cluster status JSON.
     *
     * @param string $json Raw JSON string from the cluster status special key
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException  If the JSON is syntactically invalid
     * @throws \RuntimeException If the decoded value is not an object (associative array)
     *
     * @internal
     */
    private function decodeClusterStatusJson(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Cluster status response is not a valid JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Force database recovery (use with caution!).
     *
     * This is an emergency operation that forces the database to recover.
     * May result in data loss if recent mutations haven't been replicated.
     *
     * @param string $dcId Datacenter ID to recover into. Must match
     *                     `[A-Za-z0-9_-]{1,64}`.
     *
     * @throws \InvalidArgumentException If `$dcId` is invalid.
     * @throws FDBException              If recovery fails.
     *
     * @warning This operation may cause data loss. Use only in emergency situations.
     */
    public function forceRecovery(string $dcId): void
    {
        $this->validateToken($dcId, 'forceRecovery');

        $this->database->transact(function (Transaction $tr) use ($dcId): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            // Use special key to force recovery
            $tr->set("\xff\xff/management/force_recovery", $dcId);
        });
    }

    // ----------------------------------------------------------------------
    // Input validation helpers (fix for #43).
    //
    // Each helper throws \InvalidArgumentException with a printable
    // rendering of the offending value, mirroring the
    // KeyValueLimits::assertValid*() convention.
    // ----------------------------------------------------------------------

    /**
     * Validate a tenant name against the allow-list
     * `^[A-Za-z0-9._-]{1,256}\z`, additionally rejecting names that begin
     * with `.`, `_`, or `-` (a leading dot/dash is rarely intended and
     * helps avoid hidden directory-like names).
     *
     * @param string $name  Tenant name supplied by the caller.
     * @param string $caller Calling method name, included in the
     *                      exception message so the failure can be tied
     *                      back to the offending call site.
     *
     * @throws \InvalidArgumentException If the name is empty, exceeds the
     *                                    byte-length bound, contains a
     *                                    disallowed character, or starts
     *                                    with `.`, `_`, or `-`.
     */
    private function validateTenantName(string $name, string $caller): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s: tenant name must not be empty',
                $caller,
            ));
        }

        if (strlen($name) > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                '%s: tenant name exceeds maximum length %d bytes (got %d bytes): %s',
                $caller,
                self::MAX_LABEL_LENGTH,
                strlen($name),
                $this->printableLabel($name),
            ));
        }

        if (preg_match(self::TENANT_NAME_REGEX, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s: tenant name %s contains a disallowed character; '
                . 'allowed: [A-Za-z0-9._-] (1-%d bytes)',
                $caller,
                $this->printableLabel($name),
                self::MAX_LABEL_LENGTH,
            ));
        }

        if (preg_match(self::TENANT_NAME_LEADING_REGEX, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s: tenant name %s must start with an alphanumeric character',
                $caller,
                $this->printableLabel($name),
            ));
        }
    }

    /**
     * Validate a server address (host:port) against the allow-list
     * `^[A-Za-z0-9._:-]{1,256}\z`.
     *
     * @throws \InvalidArgumentException If the address is empty, exceeds
     *                                    the byte-length bound, or
     *                                    contains a disallowed character.
     */
    private function validateAddress(string $address): void
    {
        if ($address === '') {
            throw new \InvalidArgumentException('AdminClient: server address must not be empty');
        }

        if (strlen($address) > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'AdminClient: server address exceeds maximum length %d bytes (got %d bytes): %s',
                self::MAX_LABEL_LENGTH,
                strlen($address),
                $this->printableLabel($address),
            ));
        }

        if (preg_match(self::ADDRESS_REGEX, $address) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'AdminClient: server address %s contains a disallowed character; '
                . 'allowed: [A-Za-z0-9._:-] (1-%d bytes)',
                $this->printableLabel($address),
                self::MAX_LABEL_LENGTH,
            ));
        }
    }

    /**
     * Validate a single whitespace-delimited token against the allow-list
     * `^[A-Za-z0-9_-]{1,64}\z`. Used by `configure()` for both the
     * redundancy and storage tokens, and by `forceRecovery()` for the dcId.
     *
     * @param string $value  Token supplied by the caller.
     * @param string $caller Calling method name, included in the
     *                       exception message so the failure can be tied
     *                       back to the offending call site.
     *
     * @throws \InvalidArgumentException If the token is empty, exceeds
     *                                    the byte-length bound, or
     *                                    contains a disallowed character.
     */
    private function validateToken(string $value, string $caller): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s: token must not be empty',
                $caller,
            ));
        }

        if (strlen($value) > self::MAX_TOKEN_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                '%s: token exceeds maximum length %d bytes (got %d bytes): %s',
                $caller,
                self::MAX_TOKEN_LENGTH,
                strlen($value),
                $this->printableLabel($value),
            ));
        }

        if (preg_match(self::TOKEN_REGEX, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s: token %s contains a disallowed character; '
                . 'allowed: [A-Za-z0-9_-] (1-%d bytes)',
                $caller,
                $this->printableLabel($value),
                self::MAX_TOKEN_LENGTH,
            ));
        }
    }

    /**
     * Parse and validate a `configure()` argument.
     *
     * Accepts either one token (treated as `<redundancy>`, storage defaults
     * to `"ssd"`) or two tokens (`<redundancy> <storage>`); all other shapes
     * are rejected. Each token is validated by {@see self::validateToken()}.
     *
     * @return array{0:string,1:string} Tuple `[redundancy, storage]`.
     *
     * @throws \InvalidArgumentException If the string is empty, contains
     *                                    extra whitespace / extra tokens,
     *                                    or any token fails
     *                                    {@see self::validateToken()}.
     */
    private function parseConfiguration(string $configuration): array
    {
        $trimmed = trim($configuration);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('configure: configuration string must not be empty');
        }

        // Reject leading or trailing whitespace and consecutive separators
        // up front: the configure() command does not consume them and
        // accepting them would silently drop tokens.
        if ($trimmed !== $configuration) {
            throw new \InvalidArgumentException(
                'configure: configuration string must not have leading or trailing whitespace',
            );
        }

        // Collapse internal runs of whitespace to a single space; reject
        // zero-length tokens (consecutive separators).
        $tokens = preg_split('/\s+/', $trimmed);
        if ($tokens === false) {
            throw new \InvalidArgumentException('configure: failed to split configuration string');
        }

        if (count($tokens) > 2) {
            throw new \InvalidArgumentException(sprintf(
                'configure: expected 1 or 2 whitespace-separated tokens, got %d',
                count($tokens),
            ));
        }

        foreach ($tokens as $token) {
            $this->validateToken($token, 'configure');
        }

        $redundancy = $tokens[0];
        $storage = $tokens[1] ?? 'ssd';

        return [$redundancy, $storage];
    }

    /**
     * Render a label safely for inclusion in an exception message. Bytes
     * below 0x20, DEL (0x7F), and high bytes (0x80–0xFF) are rendered as
     * `\xNN`, mirroring `printablePrefix()` in the directory layer so
     * log output never contains literal control or non-ASCII bytes from
     * caller-supplied input.
     */
    private function printableLabel(string $value): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($byte < 0x20 || $byte === 0x7F || $byte >= 0x80) {
                $out .= sprintf('\\x%02X', $byte);
            } else {
                $out .= $value[$i];
            }
        }

        return $out;
    }
}
