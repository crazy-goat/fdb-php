#!/usr/bin/env bash
#
# Runs the upstream FoundationDB binding tester against the PHP binding.
#
# Intended to be executed inside the php Docker container:
#   docker compose exec php bash tests/bindingtester/run.sh [suite ...]
#
# Suites: tuple, api, directory (defaults to all three).
# Environment:
#   BINDINGTESTER_SEED       random seed for instruction generation (default 1707)
#   BINDINGTESTER_NUM_OPS    operations per thread (default 300)
#   BINDINGTESTER_TIMEOUT    per-tester timeout in seconds (default 900)
#   FDB_CLUSTER_FILE         cluster file (default /app/fdb.cluster)

set -euo pipefail

CLUSTER_FILE="${FDB_CLUSTER_FILE:-/app/fdb.cluster}"
SEED="${BINDINGTESTER_SEED:-1707}"
NUM_OPS="${BINDINGTESTER_NUM_OPS:-300}"
TIMEOUT="${BINDINGTESTER_TIMEOUT:-900}"
API_VERSION="${BINDINGTESTER_API_VERSION:-730}"
TESTER_CMD="php /app/tests/bindingtester/tester.php"
#   BINDINGTESTER_COMPARE      optional second tester to cross-compare against
#                              (e.g. the vendored reference python tester)
COMPARE="${BINDINGTESTER_COMPARE:-}"
COMPARE_ARGS=()
if [ -n "$COMPARE" ]; then
    COMPARE_ARGS=(--compare "$COMPARE")
fi
COMPARE_ARGS=()
if [ -n "${BINDINGTESTER_COMPARE:-}" ]; then
    COMPARE_ARGS=(--compare "${BINDINGTESTER_COMPARE}")
fi

SUITES=("$@")
if [ ${#SUITES[@]} -eq 0 ]; then
    SUITES=(tuple api directory)
fi

cd /app/tests/bindingtester/upstream

# The vendored harness imports `util` as a top-level module (upstream runs it
# from inside the package directory); expose both roots.
export PYTHONPATH="/app/tests/bindingtester/upstream:/app/tests/bindingtester/upstream/bindingtester${PYTHONPATH:+:${PYTHONPATH}}"

STATUS=0
for SUITE in "${SUITES[@]}"; do
    echo "=== binding tester suite: ${SUITE} (seed=${SEED}, num-ops=${NUM_OPS}) ==="
    python3 bindingtester.py \
        --test-name "${SUITE}" \
        --api-version "${API_VERSION}" \
        --cluster-file "${CLUSTER_FILE}" \
        --seed "${SEED}" \
        --num-ops "${NUM_OPS}" \
        --timeout "${TIMEOUT}" \
        --no-threads \
        --no-directory-snapshot-ops \
        "${COMPARE_ARGS[@]}" \
        "${TESTER_CMD}" || STATUS=1
    echo
done

exit "${STATUS}"
