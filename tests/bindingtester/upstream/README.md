# Upstream FoundationDB bindingtester (vendored)

This directory contains a vendored copy of the upstream FoundationDB
**bindingtester** Python harness from
<https://github.com/apple/foundationdb> (branch `release-7.3`,
`bindings/bindingtester/`), used to drive the PHP stack machine in
`tests/bindingtester/tester.php`.

Contents:

- `bindingtester.py` — the harness entry point (generates random instruction
  streams, runs the tester command, and validates results)
- `bindingtester/` — the `bindingtester` Python package (`__init__.py`,
  `util.py`, and the test generators in `tests/`)
- `LICENSE` — the upstream Apache 2.0 license

The copy is unmodified except for this README. To update it, re-download the
same files from the upstream release branch and keep this README.
