#!/usr/bin/env bash
set -e

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

echo "Waiting for management API to be available..."
until curl -sf -u guest:guest http://127.0.0.1:15672/api/overview > /dev/null 2>&1; do
    sleep 2
    echo -n "."
done
echo ""
echo "Management API is ready."

echo "Creating restricted test user..."
# Create user with no configure permissions (can't create/delete streams)
curl -sf -u guest:guest -X PUT http://127.0.0.1:15672/api/users/restricted \
  -H "Content-Type: application/json" \
  -d '{"password":"restricted","tags":""}'
echo ""
echo "Setting permissions for restricted user (no configure permission)..."
curl -sf -u guest:guest -X PUT "http://127.0.0.1:15672/api/permissions/%2F/restricted" \
  -H "Content-Type: application/json" \
  -d '{"configure":"","write":".*","read":".*"}'
echo ""
echo "Restricted user created successfully."

echo "Creating test stream..."
curl -sf -u guest:guest -X PUT http://127.0.0.1:15672/api/queues/%2F/test-stream \
  -H "Content-Type: application/json" \
  -d '{"durable":true,"arguments":{"x-queue-type":"stream"}}' || true

echo "Running E2E tests..."
RABBITMQ_HOST=127.0.0.1 RABBITMQ_PORT=5552 ./vendor/bin/phpunit --testsuite e2e --testdox
