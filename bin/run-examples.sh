#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

timeout_command=""
if command -v timeout >/dev/null 2>&1; then
    timeout_command=timeout
elif command -v gtimeout >/dev/null 2>&1; then
    timeout_command=gtimeout
else
    echo "ERROR: timeout (or gtimeout on macOS) is required to run examples safely." >&2
    exit 1
fi

EXAMPLE_TIMEOUT="${EXAMPLE_TIMEOUT:-90}"
if ! [[ "$EXAMPLE_TIMEOUT" =~ ^[1-9][0-9]*$ ]]; then
    echo "ERROR: EXAMPLE_TIMEOUT must be a positive integer, got '$EXAMPLE_TIMEOUT'." >&2
    exit 1
fi

RABBITMQ_HOST="${RABBITMQ_HOST:-127.0.0.1}"
RABBITMQ_PORT="${RABBITMQ_PORT:-5552}"
export RABBITMQ_HOST RABBITMQ_PORT RABBITMQ_SMOKE=1

# Publishers run before consumers so every consumer has data to read. Keep this
# list explicit and verify it covers every PHP example added to the directory.
examples=(
    producer.php
    super_stream_producer.php
    basic_producer.php
    named_producer_deduplication.php
    stream_management.php
    stream_management_basic_operations.php
    basic_consumer.php
    consumer.php
    consumer_auto_commit.php
    consumer_auto_commit_full.php
    offset_resume.php
    super_stream_consumer.php
)

discovered=()
for path in examples/*.php; do
    discovered+=("${path#examples/}")
done
for discovered_example in "${discovered[@]}"; do
    configured=false
    for example in "${examples[@]}"; do
        if [[ "$example" == "$discovered_example" ]]; then
            configured=true
            break
        fi
    done
    if [[ "$configured" != true ]]; then
        echo "ERROR: $discovered_example is missing from bin/run-examples.sh." >&2
        exit 1
    fi
done
if [[ "${#discovered[@]}" -ne "${#examples[@]}" ]]; then
    echo "ERROR: bin/run-examples.sh lists ${#examples[@]} scripts but examples/ contains ${#discovered[@]} PHP scripts." >&2
    exit 1
fi

for example in "${examples[@]}"; do
    echo "=== Smoke-running examples/$example (timeout ${EXAMPLE_TIMEOUT}s) ==="
    "$timeout_command" "${EXAMPLE_TIMEOUT}s" php "examples/$example"
done
