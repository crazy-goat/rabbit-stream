# Error Handling Patterns Example

This guide provides complete, working examples of common error handling patterns in RabbitStream.

## Basic Try/Catch Example

All snippets on this page use `__DIR__ . '/../vendor/autoload.php'`, so save a snippet as a file in a subdirectory of the repository root (for example `examples/`) before running it with `php`.

The foundation of error handling is proper exception catching:

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    // Connection::create() runs the full handshake (PeerProperties, SASL,
    // Tune, Open) and throws on failure.
    $connection = Connection::create(
        host: 'localhost',
        port: 5552,
        user: 'guest',
        password: 'guest',
    );

    echo "Connected successfully!\n";

    $connection->close();
} catch (RabbitStreamExceptionInterface $e) {
    // One clause covers every throwable the library raises.
    echo "RabbitStream error: " . $e->getMessage() . "\n";
    exit(1);
}
```

## Connection Error Handling

Handle connection failures with retry logic:

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

require_once __DIR__ . '/../vendor/autoload.php';

function connectWithRetry(
    string $host,
    int $port,
    string $username,
    string $password,
    string $vhost = '/',
    int $maxRetries = 3
): Connection {
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            echo "Connection attempt $attempt of $maxRetries...\n";

            // create() connects and runs the whole handshake in one call.
            return Connection::create(
                host: $host,
                port: $port,
                user: $username,
                password: $password,
                vhost: $vhost,
            );

        } catch (TimeoutException $e) {
            // TimeoutException extends ConnectionException, so it must be
            // caught first.
            $lastException = $e;
            echo "Timeout on attempt $attempt\n";

            if ($attempt < $maxRetries) {
                $delay = $attempt * 2; // Exponential backoff
                echo "Waiting {$delay}s before retry...\n";
                sleep($delay);
            }
        } catch (ConnectionException $e) {
            $lastException = $e;
            echo "Connection error on attempt $attempt: " . $e->getMessage() . "\n";

            if ($attempt < $maxRetries) {
                sleep(2);
            }
        }
    }

    throw $lastException;
}

// Usage
try {
    $connection = connectWithRetry('localhost', 5552, 'guest', 'guest');
    // Use connection...
    $connection->close();
} catch (ConnectionException $e) {
    echo "Failed to connect after retries: " . $e->getMessage() . "\n";
    exit(1);
}
```

## Authentication Error Handling

Handle various authentication scenarios:

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once __DIR__ . '/../vendor/autoload.php';

function connectWithErrorHandling(
    string $host,
    int $port,
    string $username,
    string $password,
    string $vhost
): ?Connection {
    try {
        // Authentication happens inside Connection::create().
        return Connection::create(
            host: $host,
            port: $port,
            user: $username,
            password: $password,
            vhost: $vhost,
        );
    } catch (AuthenticationException $e) {
        // The server's SASL handshake does not offer PLAIN at all.
        echo "Authentication failed: PLAIN mechanism not supported\n";
        return null;
    } catch (ProtocolException $e) {
        // A bad username/password is an AUTHENTICATION_FAILURE response code.
        switch ($e->getResponseCode()) {
            case ResponseCodeEnum::AUTHENTICATION_FAILURE:
                echo "Authentication failed: Invalid username or password\n";
                break;
            case ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE:
                echo "Authentication failed: Cannot access virtual host '$vhost'\n";
                break;
            case ResponseCodeEnum::ACCESS_REFUSED:
                echo "Authentication failed: Access refused\n";
                break;
            default:
                echo "Authentication failed: " . $e->getMessage() . "\n";
        }
        return null;
    }
}

// Usage
$connection = connectWithErrorHandling('localhost', 5552, 'guest', 'wrong-password', '/');

if ($connection === null) {
    echo "Please check your credentials and try again\n";
    exit(1);
}

echo "Authenticated successfully!\n";
$connection->close();
```

## Publish with Confirmation Checking

Handle publish confirmations and errors:

```php
<?php

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once __DIR__ . '/../vendor/autoload.php';

class PublisherWithErrorHandling
{
    private ?ProducerInterface $producer = null;
    private array $failedMessages = [];

    public function __construct(private Connection $connection)
    {
    }

    public function createProducer(string $stream): bool
    {
        try {
            // createProducer() declares the publisher eagerly, so a missing
            // stream is reported here rather than on the first send().
            $this->producer = $this->connection->createProducer(
                $stream,
                onConfirm: function (ConfirmationStatus $status): void {
                    if ($status->isConfirmed()) {
                        echo "Message " . $status->getPublishingId() . " confirmed\n";
                    } else {
                        $errorCode = $status->getErrorCode();
                        $publishingId = $status->getPublishingId();

                        echo "Message $publishingId failed with error code: $errorCode\n";
                        $this->failedMessages[] = [
                            'id' => $publishingId,
                            'error' => $errorCode,
                        ];
                    }
                }
            );
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();

            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
                echo "Stream '$stream' does not exist. Creating it...\n";
                try {
                    $this->connection->createStream($stream);
                    return $this->createProducer($stream);
                } catch (ProtocolException $e2) {
                    echo "Failed to create stream: " . $e2->getMessage() . "\n";
                    return false;
                }
            }

            if ($code === ResponseCodeEnum::ACCESS_REFUSED) {
                echo "Access denied: Cannot publish to stream '$stream'\n";
                return false;
            }

            throw $e;
        }
    }

    public function publish(string $message): void
    {
        $this->producer?->send($message);
    }

    public function waitForConfirms(float $timeout = 5.0): void
    {
        $this->producer?->waitForConfirms(timeout: $timeout);
    }

    public function getFailedMessages(): array
    {
        return $this->failedMessages;
    }
}

// Usage
$connection = Connection::create(
    host: 'localhost',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

$publisher = new PublisherWithErrorHandling($connection);

if (!$publisher->createProducer('my-stream')) {
    echo "Failed to create producer\n";
    exit(1);
}

// Publish some messages
for ($i = 1; $i <= 5; $i++) {
    $publisher->publish("Message $i");
}

// Wait for confirmations
$publisher->waitForConfirms(timeout: 5.0);

// Check for failures
$failed = $publisher->getFailedMessages();
if (!empty($failed)) {
    echo "Failed to publish " . count($failed) . " messages\n";
}

$connection->close();
```

## Consumer with Offset Handling

Handle the missing-offset scenario and subscription errors:

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

class ConsumerWithOffsetHandling
{
    private ?ConsumerInterface $consumer = null;

    public function __construct(
        private Connection $connection,
        private string $stream,
        private string $reference,
    ) {
    }

    public function subscribe(): bool
    {
        try {
            // queryOffset() returns null — not an exception — when nothing has
            // been stored for this name/stream pair (NO_OFFSET, 0x13).
            $stored = $this->connection->queryOffset($this->reference, $this->stream);

            if ($stored === null) {
                echo "No stored offset found. Starting from the beginning...\n";
                $offset = OffsetSpec::first();
            } else {
                echo "Resuming from stored offset $stored\n";
                $offset = OffsetSpec::offset($stored);
            }

            $this->consumer = $this->connection->createConsumer(
                $this->stream,
                $offset,
                name: $this->reference,
            );
            echo "Subscribed to stream '{$this->stream}'\n";
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();

            if ($code === ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS) {
                echo "Subscription id already in use\n";
                return false;
            }

            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
                echo "Stream '{$this->stream}' does not exist\n";
                return false;
            }

            if ($code === ResponseCodeEnum::ACCESS_REFUSED) {
                echo "Access denied: Cannot consume from stream '{$this->stream}'\n";
                return false;
            }

            throw $e;
        }
    }

    public function read(int $maxMessages): void
    {
        if ($this->consumer === null) {
            return;
        }

        $processed = 0;
        while ($processed < $maxMessages) {
            // read() returns an empty array (not an exception) on an elapsed
            // timeout; that is not end-of-stream.
            $messages = $this->consumer->read(timeout: 5.0);
            if ($messages === []) {
                echo "No messages in the last 5s\n";
                continue;
            }

            foreach ($messages as $message) {
                echo "Received: " . $message->getBody() . "\n";
                // storeOffset() records the next offset to consume.
                $this->consumer->storeOffset($message->getOffset() + 1);
                $processed++;
            }
        }
    }

    public function close(): void
    {
        $this->consumer?->close();
    }
}

// Usage
$connection = Connection::create(
    host: 'localhost',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

$consumer = new ConsumerWithOffsetHandling($connection, 'my-stream', 'my-consumer-group');

if (!$consumer->subscribe()) {
    echo "Failed to subscribe\n";
    exit(1);
}

$consumer->read(10);

// Clean shutdown
$consumer->close();
$connection->close();
```

## Timeout Handling Example

Implement timeout handling with retry:

```php
<?php

use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;

require_once __DIR__ . '/../vendor/autoload.php';

function waitForConfirmsWithRetry(
    ProducerInterface $producer,
    float $timeout = 10.0,
    int $maxRetries = 2
): void {
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            // waitForConfirms() throws TimeoutException when the broker does
            // not confirm the outstanding messages in time.
            $producer->waitForConfirms(timeout: $timeout);
            return;
        } catch (TimeoutException $e) {
            $lastException = $e;
            echo "Timeout on attempt $attempt ({$timeout}s)\n";

            if ($attempt < $maxRetries) {
                echo "Retrying...\n";
            }
        } catch (ConnectionException $e) {
            // The connection is gone; retrying on it is pointless.
            echo "Connection lost, re-establish it with Connection::create()\n";
            throw $e;
        }
    }

    throw $lastException;
}

// Usage
$connection = Connection::create(
    host: 'localhost',
    port: 5552,
    user: 'guest',
    password: 'guest',
);
$producer = $connection->createProducer('my-stream');

$producer->send('Hello');

try {
    waitForConfirmsWithRetry($producer, 5.0, 2);
    echo "Message confirmed\n";
} catch (TimeoutException $e) {
    echo "Confirmation timed out after retries\n";
    // Handle timeout - maybe continue with other work
}

$connection->close();
```

## Complete Working Example

A comprehensive example combining all patterns:

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

class RobustStreamClient
{
    private ?Connection $connection = null;
    private ?ProducerInterface $producer = null;
    private ?ConsumerInterface $consumer = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $vhost = '/'
    ) {
    }

    public function connect(int $maxRetries = 3): bool
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                echo "Connecting (attempt $attempt/$maxRetries)...\n";

                $this->connection = Connection::create(
                    host: $this->host,
                    port: $this->port,
                    user: $this->username,
                    password: $this->password,
                    vhost: $this->vhost,
                );

                echo "Connected successfully!\n";
                return true;

            } catch (AuthenticationException $e) {
                echo "Authentication failed: " . $e->getMessage() . "\n";
                return false; // Don't retry auth failures
            } catch (ProtocolException $e) {
                $code = $e->getResponseCode();
                if ($code === ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE) {
                    echo "Cannot access virtual host '{$this->vhost}'\n";
                    return false;
                }
                $lastException = $e;
            } catch (ConnectionException $e) {
                $lastException = $e;
                echo "Connection error: " . $e->getMessage() . "\n";
            }

            if ($attempt < $maxRetries) {
                sleep($attempt); // Exponential backoff
            }
        }

        throw $lastException;
    }

    public function ensureStreamExists(string $stream): bool
    {
        try {
            $this->connection->createStream($stream);
            echo "Created stream: $stream\n";
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();

            if ($code === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
                echo "Stream already exists: $stream\n";
                return true;
            }

            if ($code === ResponseCodeEnum::ACCESS_REFUSED) {
                echo "Access denied creating stream: $stream\n";
                return false;
            }

            throw $e;
        }
    }

    public function createProducer(string $stream): bool
    {
        try {
            $this->producer = $this->connection->createProducer($stream);
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();

            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
                echo "Stream does not exist: $stream\n";
                return false;
            }

            throw $e;
        }
    }

    public function publish(string $data): void
    {
        $this->producer?->send($data);
    }

    public function waitForConfirms(float $timeout = 5.0): void
    {
        $this->producer?->waitForConfirms(timeout: $timeout);
    }

    /** @return \CrazyGoat\RabbitStream\Client\Message[] */
    public function read(float $timeout = 5.0): array
    {
        return $this->consumer?->read($timeout) ?? [];
    }

    public function subscribe(string $stream, string $reference): bool
    {
        try {
            $stored = $this->connection->queryOffset($reference, $stream);
            $offset = $stored === null ? OffsetSpec::first() : OffsetSpec::offset($stored);

            $this->consumer = $this->connection->createConsumer(
                $stream,
                $offset,
                name: $reference,
            );
            echo "Subscribed to $stream\n";
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();

            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
                echo "Stream does not exist: $stream\n";
                return false;
            }

            throw $e;
        }
    }

    public function close(): void
    {
        try {
            $this->consumer?->close();
            $this->producer?->close();
            $this->connection?->close();
        } catch (RabbitStreamExceptionInterface $e) {
            echo "Error during close: " . $e->getMessage() . "\n";
        } finally {
            $this->connection = null;
            $this->producer = null;
            $this->consumer = null;
        }
    }
}

// Main execution
$client = new RobustStreamClient('localhost', 5552, 'guest', 'guest', '/');

try {
    // Connect with retry
    if (!$client->connect(3)) {
        echo "Failed to connect\n";
        exit(1);
    }

    // Ensure stream exists
    if (!$client->ensureStreamExists('test-stream')) {
        echo "Cannot access stream\n";
        exit(1);
    }

    // Create producer
    if (!$client->createProducer('test-stream')) {
        echo "Failed to create producer\n";
        exit(1);
    }

    // Publish messages
    for ($i = 1; $i <= 3; $i++) {
        $client->publish("Test message $i");
    }
    $client->waitForConfirms(timeout: 5.0);

    // Subscribe and consume
    if ($client->subscribe('test-stream', 'test-consumer')) {
        // In real usage, you'd consume messages here, e.g.:
        // $messages = $client->read(timeout: 5.0);
    }

} catch (RabbitStreamExceptionInterface $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $client->close();
}

echo "All operations completed successfully!\n";
```

## Error Logging Pattern

A comprehensive logging pattern for production use:

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

function logException(RabbitStreamExceptionInterface $e, array $context = []): void
{
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'exception_class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'context' => $context,
    ];

    if ($e instanceof ProtocolException) {
        $responseCode = $e->getResponseCode();
        $logEntry['response_code'] = $responseCode ? [
            'name' => $responseCode->name,
            'value' => $responseCode->value,
            'message' => $responseCode->getMessage(),
        ] : null;
    }

    if ($e instanceof ConnectionException) {
        $logEntry['is_retryable'] = !($e instanceof TimeoutException);
    }

    // Log to file or monitoring system
    error_log(json_encode($logEntry, JSON_PRETTY_PRINT));
}

// Usage example
$connection = Connection::create(
    host: 'localhost',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

try {
    $connection->createProducer('my-stream');
} catch (RabbitStreamExceptionInterface $e) {
    logException($e, [
        'operation' => 'createProducer',
        'stream' => 'my-stream',
    ]);
    throw $e;
}
```

## Summary

These examples demonstrate:

1. **Basic error handling** - Catching and handling exceptions
2. **Connection retries** - Exponential backoff for transient failures
3. **Authentication handling** - Distinguishing auth failures from other errors
4. **Publish confirmations** - Checking async confirmation status
5. **Consumer offset handling** - Graceful NO_OFFSET handling
6. **Timeout management** - Retry logic for timeouts
7. **Complete integration** - All patterns working together
8. **Production logging** - Structured error logging

For more details on specific error types, see the [Error Handling Guide](../guide/error-handling.md).
