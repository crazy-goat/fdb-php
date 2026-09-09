# Binding tester (cross-binding conformance)

This project ships a driver for the upstream FoundationDB **binding tester**
([issue #96]). The binding tester generates a randomized stream of
instructions, feeds them to the binding through a well-defined stack machine
protocol, and validates the results — verifying that a PHP-written key is
byte-identical to one written by the Java, Python, Go or Ruby bindings.

## Components

- `tests/bindingtester/tester.php` — the PHP stack machine implementing the
  upstream protocol
  ([bindingApiTester.md](https://github.com/apple/foundationdb/blob/release-7.3/bindings/bindingtester/spec/bindingApiTester.md))
  including the directory layer extension and the `UNIT_TESTS` hook
  (transaction options, watches, cancellation, retry limits, timeouts,
  error predicates).
- `tests/bindingtester/upstream/` — a vendored, unmodified copy of the
  upstream Python harness (`bindingtester.py`) that generates the
  instructions and validates the results.
- `tests/bindingtester/run.sh` — convenience runner for the suites.

## Running locally

```bash
make up                          # start the FDB cluster + PHP container
make bindingtester               # run tuple, api and directory suites
make bindingtester SUITES="tuple"  # run a single suite
```

Or directly inside the container:

```bash
docker compose exec php bash tests/bindingtester/run.sh tuple api
```

Tunables (environment variables, see `run.sh`):

| Variable                | Default | Meaning                          |
|-------------------------|---------|----------------------------------|
| `BINDINGTESTER_SEED`    | `1707`  | random seed for generation       |
| `BINDINGTESTER_NUM_OPS` | `300`   | operations per thread            |
| `BINDINGTESTER_TIMEOUT` | `900`   | per-tester timeout (seconds)     |
| `BINDINGTESTER_API_VERSION` | `730` | API version used by the test  |

## Suites

- **tuple** — tuple pack/unpack round-trips, ordering, `TUPLE_SORT`,
  `ENCODE_FLOAT`/`ENCODE_DOUBLE`. Cheap and high value; run it in CI.
- **api** — core operations, key selectors, atomic ops, transaction
  lifecycle, versionstamps (single-threaded; the blocking-future limitation
  from [#92] means high-concurrency runs are out of scope for now).
- **directory** — directory layer create/open/move/remove, partitions,
  layer strings. Requires `--no-directory-snapshot-ops` (passed by `run.sh`)
  because snapshot directory reads are not yet supported by the PHP
  directory layer.

Currently the tuple and api suites are the gating ones; the directory suite
exposes known gaps in the PHP directory layer (root-level `moveTo`,
`allow_manual_prefixes`) and is informational.

### Current conformance status

- **tuple** — passes byte-identically against the vendored Python reference
  tester (`--compare python3 .../python_tests/tester.py`) across multiple
  seeds, after fixing two canonical-encoding bugs in the tuple layer
  (boundary integers `±(2^64-1)`).
- **api** — passes against the Python reference tester as well (single
  threaded, no tenants, no versionstamp types).
- **directory** — passes the harness' own validation (state tree, logs) on
  multiple seeds. Cross-comparison against the Python reference currently
  diverges on directory log details (path/layer typing, root-level `moveTo`,
  HCA prefix bookkeeping) and is tracked as follow-up work.

## CI

A scheduled (nightly) GitHub Actions workflow runs the suites against the
5-node Docker cluster (`.github/workflows/bindingtester.yml`), and can also
be triggered manually via `workflow_dispatch`.

[#96]: https://github.com/crazy-goat/fdb-php/issues/96
[#92]: https://github.com/crazy-goat/fdb-php/issues/92
