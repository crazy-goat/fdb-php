# AGENTS.md

Project commands and specifics for fdb-php, a FoundationDB client for PHP (FFI over
`libfdb_c`). The development process (issue, worktree, review, PR, merge) is in
[docs/workflow.md](docs/workflow.md), the release process in
[docs/release-workflow.md](docs/release-workflow.md). The default branch is `master`.

Everything is written in English (code, comments, docs, commits, issues).

## Layout

| Path | Content |
|---|---|
| `src/` | Library, namespace `CrazyGoat\FoundationDB\` |
| `tests/Unit/` | Unit suite, needs no FoundationDB |
| `tests/Integration/` | Integration suite, needs a running FoundationDB cluster |
| `tests/bindingtester/` | Driver for the upstream binding tester (`upstream/` is a vendored Python harness) |
| `docs/` | User documentation (one file per topic), `bindingtester.md`, process docs |
| `docker/php/` | PHP CLI image with `libfdb_c` (used by `docker-compose.yml`) |
| `docker-compose.yml` | 3 coordinators, 2 servers, a `fdb-config` job and the `php` container |
| `examples/` | Usage examples |
| `bin/` | Worktree scripts and `pick-issue.sh` |

## Commands

PHP 8.2+ with `ext-ffi` and `ext-gmp`, plus the FoundationDB client library `libfdb_c` 7.3.x
for anything that opens a database. CI runs PHP 8.2, 8.3 and 8.4.

```bash
composer install

# Lint: PHPCS + Rector (dry run) + PHPStan (level 9) + shellcheck + hadolint; check only
bin/lint.sh              # same as composer lint and make lint
bin/lint.sh --fix        # Rector (apply) + PHPCBF, then check (composer lint:fix, make lint-fix)
composer cs              # PHPCS only
composer cs-fix          # PHPCBF only
composer phpstan         # PHPStan only
composer rector          # Rector dry run
composer rector:fix      # Rector apply

# Tests
composer test            # all suites
composer test:unit       # Unit suite, no FoundationDB needed
composer test:integration  # Integration suite, needs a cluster (see below)
```

Run `bin/lint.sh --fix` and then `bin/lint.sh` before committing. Fix by hand what cannot be
auto-fixed. Push only when `bin/lint.sh` and `composer test` pass (in a worktree: `bin/lint.sh`
on the host and `make test`, see below). `bin/lint.sh` runs on the host, not in the php
container; it needs `shellcheck` and `vendor/` from `composer install`.

## FoundationDB and Docker

- `make` targets wrap `docker compose exec php ...`: `make up`, `make down`, `make test`,
  `make test-unit`, `make test-integration`, `make bindingtester`, `make help`. `make lint` is the exception: it
  runs `bin/lint.sh` on the host.
- Start the cluster with `docker compose up -d` (3 coordinators, 2 storage servers, `fdb-config`
  and `php`). Run the integration suite inside the php container:
  `docker compose exec php vendor/bin/phpunit --testsuite=Integration`. Stop with
  `docker compose down -v`.
- Coordinators advertise `fdb-coord-N:PORT` inside the compose network. Tests that run in the
  php container use `FDB_CLUSTER_FILE=/app/fdb.cluster`, written by the container.
- Host ports are variables with the old defaults: `FDB_COORD_1_PORT` (4500), `FDB_COORD_2_PORT`
  (4501), `FDB_COORD_3_PORT` (4502), `FDB_SERVER_1_PORT` (4510), `FDB_SERVER_2_PORT` (4511).
  `bin/worktree.sh` writes free ports to `.env.worktree`; load it with
  `set -a && . ./.env.worktree && set +a`. `bin/worktree-teardown.sh` stops the stack of the
  worktree. `bin/worktree-setup.sh` only runs `composer install`; it does not start FoundationDB.
- **Worktrees:** the published ports in `.env.worktree` are non-default, and the host cannot use
  them. FoundationDB requires the port a client dials to equal the address the coordinator
  advertises (4500-4502), otherwise the client aborts. So in a worktree: load `.env.worktree`,
  run `docker compose up -d`, and run tests inside the php container: `make test`,
  `make test-integration`, or `docker compose exec php ...`. Host-side
  `composer test:integration` with a `127.0.0.1:4500-4502` cluster file works only with the
  default ports (the main checkout and CI).
- CI (`e2e-tests`) starts the cluster with the default ports, writes a host-side cluster file
  with `127.0.0.1:4500-4502` and sets `FDB_REBOOT_TEST_IP` to the container address of
  `fdb-server-1`.
- Integration tests read `FDB_CLUSTER_FILE` and `FDB_REBOOT_TEST_IP` from the environment.
- The binding tester (`make bindingtester`, see `docs/bindingtester.md`) runs nightly in
  `.github/workflows/bindingtester.yml`. It is not part of `ci-ok`.

## CI

`.github/workflows/ci.yml` runs on pull requests and on pushes to `master`. The `changes` job
detects documentation-only changes and the `docs` job checks them fast. `check-actor`, `lint`,
`unit-tests` and `e2e-tests` run only for code changes by owners, collaborators and
`dependabot[bot]`. `ci-ok`
aggregates the results and is the required check. `.github/workflows/release.yml` creates the
GitHub Release when a `v*` tag is pushed.

## Conventions

- Commit and PR titles are Conventional Commits with the issue number:
  `fix(#NN): description`, `feat(#NN): description`. Chores without an issue: `chore: ...`.
- Issue titles use a tag: `[Tag] Short description`.

  | Tag | When to use |
  |---|---|
  | `[Bug]` | Something does not work as documented or expected |
  | `[Security]` | Vulnerability, trust boundary, validation gap |
  | `[Reliability]` | Crash, hang, data loss, retry problems |
  | `[Memory]` | Leak, unbounded growth, reference cycle |
  | `[Maintainability]` | Duplicated code, oversized API, technical debt |
  | `[Tests]` | Missing or weak tests, flaky tests |
  | `[Info]` | Low-priority hygiene, documentation, minor improvements |

- An issue also states the affected files and locations, the proposed approach and the severity
  (Critical, High, Medium, Low, Info). After the work, comment on the issue with what was changed.
- `CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/). Add entries under
  `## [Unreleased]` in Added, Changed, Deprecated, Removed, Fixed or Security. An entry starts
  with the issue number: `- [#NN] Description`.
- New code gets tests: unit tests in `tests/Unit/`, integration tests in `tests/Integration/`.

## Common lint issues

- **PHPCS, multi-line function declaration** ("The closing parenthesis and the opening brace
  ... must be on the same line"): run `bin/lint.sh --fix`, or fix the formatting by hand.
- **Rector, unused parameters:** Rector may remove parameters from methods that are not
  implemented yet. If the method will be implemented later, the parameters must stay.
- **PHPStan, property only written:** if a property is only written and never read, PHPStan
  reports an error. Use `@phpstan-ignore property.onlyWritten` if the property will be used later.
