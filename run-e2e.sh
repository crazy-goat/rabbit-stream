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
# Keep this wait longer than the Compose healthcheck budget (see docker-compose.yml),
# so a slow broker can finish its healthchecks before this script gives up.
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

# Pull the broker image before compose starts it. Docker Hub is not in the list: its anonymous
# pull limit is shared by GitHub-hosted runners (#826). ECR Public has a similar limit (#872),
# so it is only the fallback. An image set in the environment is the only candidate.
if [ -n "${RABBITMQ_IMAGE:-}" ]; then
    RABBITMQ_IMAGE_CANDIDATES=("$RABBITMQ_IMAGE")
else
    RABBITMQ_IMAGE_CANDIDATES=(
        "mirror.gcr.io/library/rabbitmq:4-management"
        "public.ecr.aws/docker/library/rabbitmq:4-management"
    )
fi
PULL_ATTEMPTS=3

pull_rabbitmq_image() {
    local image attempt
    for image in "${RABBITMQ_IMAGE_CANDIDATES[@]}"; do
        for attempt in $(seq 1 "$PULL_ATTEMPTS"); do
            echo "Pulling $image (attempt $attempt of $PULL_ATTEMPTS)..."
            if docker pull "$image"; then
                RABBITMQ_IMAGE="$image"
                export RABBITMQ_IMAGE
                return 0
            fi
            if [ "$attempt" -lt "$PULL_ATTEMPTS" ]; then
                # Back off 5 seconds, then 10 seconds, before the next attempt.
                sleep $((attempt * 5))
            fi
        done
        echo "WARNING: could not pull $image; trying the next candidate." >&2
    done
    return 1
}

# Print the first candidate that already exists in the local Docker cache.
find_cached_rabbitmq_image() {
    local image
    for image in "${RABBITMQ_IMAGE_CANDIDATES[@]}"; do
        if docker image inspect "$image" >/dev/null 2>&1; then
            echo "$image"
            return 0
        fi
    done
    return 1
}

if ! pull_rabbitmq_image; then
    # No registry answered. A cached copy still lets the broker start offline.
    if ! cached_image="$(find_cached_rabbitmq_image)"; then
        echo "ERROR: could not pull the RabbitMQ image and no candidate is cached locally." >&2
        exit 1
    fi
    RABBITMQ_IMAGE="$cached_image"
    export RABBITMQ_IMAGE
    echo "WARNING: using the cached image $RABBITMQ_IMAGE; the registry pull failed." >&2
fi

cleanup() {
    echo "Stopping RabbitMQ..."
    docker compose down
}
trap cleanup EXIT

echo "Starting RabbitMQ..."
if ! docker compose up -d; then
    echo "ERROR: could not start the RabbitMQ broker." >&2
    exit 1
fi

echo "Waiting for RabbitMQ to be healthy..."
# Resolve the container from the compose project (a worktree sets COMPOSE_PROJECT_NAME),
# never from a fixed container name, so remapped ports and parallel worktrees keep working.
container_id="$(docker compose ps -q rabbitmq || true)"
if [ -z "$container_id" ]; then
    echo "ERROR: 'docker compose up -d' started no 'rabbitmq' container." >&2
    exit 1
fi
if [[ "$container_id" == *$'\n'* ]]; then
    echo "ERROR: found multiple 'rabbitmq' containers; scaling is not supported." >&2
    exit 1
fi

# Automatic free-port allocation above uses host `python3` when ports are unset; health polling
# uses `docker inspect` and needs no host `python3` or `docker compose ps --format json` (#472).
status=""
attempt=0
while [ "$attempt" -lt "$HEALTH_RETRIES" ]; do
    attempt=$((attempt + 1))
    status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || echo unknown)"
    if [ "$status" = "healthy" ]; then
        break
    fi
    case "$status" in
        unhealthy|exited|dead)
            break
            ;;
    esac
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
