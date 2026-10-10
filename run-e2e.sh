#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"

# In a worktree, load the unique compose project name and host ports.
# Values already exported in the environment win over .env.worktree.
if [ -f .env.worktree ]; then
    while IFS='=' read -r key value; do
        case "$key" in ''|\#*) continue ;; esac
        if [ -z "${!key+x}" ]; then
            export "$key=$value"
        fi
    done < .env.worktree
fi
# Give runs without a worktree environment their own Compose resources too.
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-rabbit-stream-$$-$RANDOM}"
export COMPOSE_PROJECT_NAME
RABBITMQ_HOST="${RABBITMQ_HOST:-127.0.0.1}"
if [ -z "${RABBITMQ_PORT+x}" ]; then
    RABBITMQ_PORT="$(bash bin/free-port.sh)"
fi
if [ -z "${RABBITMQ_AMQP_PORT+x}" ]; then
    RABBITMQ_AMQP_PORT="$(bash bin/free-port.sh)"
fi
if [ -z "${RABBITMQ_MANAGEMENT_PORT+x}" ]; then
    RABBITMQ_MANAGEMENT_PORT="$(bash bin/free-port.sh)"
fi
export RABBITMQ_HOST RABBITMQ_PORT RABBITMQ_AMQP_PORT RABBITMQ_MANAGEMENT_PORT

# Health-wait bounds: E2E_HEALTH_RETRIES polls, E2E_HEALTH_INTERVAL seconds apart.
# The defaults cap the wait at three minutes instead of polling forever (#472).
HEALTH_RETRIES="${E2E_HEALTH_RETRIES:-90}"
HEALTH_INTERVAL="${E2E_HEALTH_INTERVAL:-2}"

if ! [[ "$HEALTH_RETRIES" =~ ^[0-9]*[1-9][0-9]*$ ]]; then
    echo "ERROR: E2E_HEALTH_RETRIES must be a positive integer, got '$HEALTH_RETRIES'." >&2
    exit 1
fi
if ! [[ "$HEALTH_INTERVAL" =~ ^[0-9]+$ ]]; then
    echo "ERROR: E2E_HEALTH_INTERVAL must be a non-negative integer, got '$HEALTH_INTERVAL'." >&2
    exit 1
fi

cleanup() {
    echo "Stopping RabbitMQ..."
    docker compose down
}
trap cleanup EXIT

echo "Starting RabbitMQ..."
docker compose up -d

echo "Waiting for RabbitMQ to be healthy..."
# Resolve the container from the compose project (a worktree sets COMPOSE_PROJECT_NAME),
# never from a fixed container name, so remapped ports and parallel worktrees keep working.
container_id="$(docker compose ps -q rabbitmq || true)"
if [ -z "$container_id" ]; then
    echo "ERROR: 'docker compose up -d' started no 'rabbitmq' container." >&2
    exit 1
fi

# `docker inspect` reads health status directly, so health polling needs neither host `python3`
# nor the JSON shape `docker compose ps --format json` happens to emit (#472).
status=""
attempt=0
while [ "$attempt" -lt "$HEALTH_RETRIES" ]; do
    attempt=$((attempt + 1))
    status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || echo unknown)"
    if [ "$status" = "healthy" ]; then
        break
    fi
    echo -n "."
    sleep "$HEALTH_INTERVAL"
done

if [ "$status" != "healthy" ]; then
    echo ""
    echo "ERROR: RabbitMQ is not healthy after ${attempt} poll(s) (${HEALTH_INTERVAL}s apart); last status: '${status}'." >&2
    echo "--- docker compose logs --tail=50 rabbitmq ---" >&2
    docker compose logs --tail=50 rabbitmq >&2 || true
    exit 1
fi
echo ""
echo "RabbitMQ is ready."

echo "Running E2E tests..."
./vendor/bin/phpunit --testsuite e2e --testdox

# Keep the broker running while every example is smoke-tested.
echo "Running example smoke tests..."
bin/run-examples.sh
