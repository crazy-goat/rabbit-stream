#!/usr/bin/env bash
set -e

# In a worktree, load the unique compose project name and host ports.
if [ -f .env.worktree ]; then
    set -a
    . ./.env.worktree
    set +a
fi
RABBITMQ_HOST="${RABBITMQ_HOST:-127.0.0.1}"
RABBITMQ_PORT="${RABBITMQ_PORT:-5552}"
RABBITMQ_MANAGEMENT_PORT="${RABBITMQ_MANAGEMENT_PORT:-15672}"
export RABBITMQ_HOST RABBITMQ_PORT RABBITMQ_MANAGEMENT_PORT

cleanup() {
    echo "Stopping RabbitMQ..."
    docker compose down
}
trap cleanup EXIT

echo "Starting RabbitMQ..."
docker compose up -d

echo "Waiting for RabbitMQ to be healthy..."
until [ "$(docker compose ps --format json rabbitmq | python3 -c "import sys,json; print(json.load(sys.stdin).get('Health',''))" 2>/dev/null)" = "healthy" ]; do
    sleep 2
    echo -n "."
done
echo ""
echo "RabbitMQ is ready."

echo "Creating test stream..."
curl -sf -u guest:guest -X PUT http://${RABBITMQ_HOST}:${RABBITMQ_MANAGEMENT_PORT}/api/queues/%2F/test-stream \
  -H "Content-Type: application/json" \
  -d '{"durable":true,"arguments":{"x-queue-type":"stream"}}' || true

echo "Running E2E tests..."
./vendor/bin/phpunit --testsuite e2e --testdox
