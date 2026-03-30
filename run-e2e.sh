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
# Retry up to 5 times with delay in case management API isn't fully ready
USER_CREATED=false
for i in {1..5}; do
    echo "Attempt $i: Creating user 'restricted'..."
    HTTP_CODE=$(curl -s -w "%{http_code}" -o /tmp/curl_user_output.txt -u guest:guest -X PUT http://127.0.0.1:15672/api/users/restricted \
      -H "Content-Type: application/json" \
      -d '{"password":"restricted","tags":""}')
    echo "HTTP Code: $HTTP_CODE"
    if [ -f /tmp/curl_user_output.txt ]; then
        cat /tmp/curl_user_output.txt
    fi
    if [[ "$HTTP_CODE" =~ ^2 ]]; then
        echo "User created successfully"
        USER_CREATED=true
        break
    fi
    if [ $i -eq 5 ]; then
        echo "WARNING: Failed to create restricted user after 5 attempts"
        echo "Tests requiring restricted user will be skipped"
    fi
    sleep 2
done

if [ "$USER_CREATED" = true ]; then
    echo "Setting permissions for restricted user (no configure permission)..."
    for i in {1..5}; do
        echo "Attempt $i: Setting permissions..."
        HTTP_CODE=$(curl -s -w "%{http_code}" -o /tmp/curl_perm_output.txt -u guest:guest -X PUT "http://127.0.0.1:15672/api/permissions/%2F/restricted" \
          -H "Content-Type: application/json" \
          -d '{"configure":"","write":".*","read":".*"}')
        echo "HTTP Code: $HTTP_CODE"
        if [ -f /tmp/curl_perm_output.txt ]; then
            cat /tmp/curl_perm_output.txt
        fi
        if [[ "$HTTP_CODE" =~ ^2 ]]; then
            echo "Permissions set successfully"
            break
        fi
        if [ $i -eq 5 ]; then
            echo "WARNING: Failed to set permissions after 5 attempts"
        fi
        sleep 2
    done
    
    # Verify the user was created properly
    echo "Verifying restricted user exists..."
    curl -s -u guest:guest http://127.0.0.1:15672/api/users/restricted | head -c 500
    echo ""
fi
echo "Restricted user setup complete."

echo "Creating test stream..."
curl -sf -u guest:guest -X PUT http://127.0.0.1:15672/api/queues/%2F/test-stream \
  -H "Content-Type: application/json" \
  -d '{"durable":true,"arguments":{"x-queue-type":"stream"}}' || true

echo "Running E2E tests..."
RABBITMQ_HOST=127.0.0.1 RABBITMQ_PORT=5552 ./vendor/bin/phpunit --testsuite e2e --testdox
