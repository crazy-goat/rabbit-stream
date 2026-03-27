# Error Handling Patterns Example

This guide provides complete, working examples of common error handling patterns in RabbitStream.

## Basic Try/Catch Example

The foundation of error handling is proper exception catching:

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Exception\RabbitStreamException;

require_once 'vendor/autoload.php';

try {
    $connection = new StreamConnection('localhost', 5552);
    $connection->connect();
    $connection->authenticate('guest', 'guest');
    $connection->open('/');
    
    echo "Connected successfully!\n";
    
    $connection->close();
} catch (RabbitStreamException $e) {
    echo "RabbitStream error: " . $e->getMessage() . "\n";
    exit(1);
} catch (\Exception $e) {
    echo "Unexpected error: " . $e->getMessage() . "\n";
    exit(1);
}
```

## Connection Error Handling

Handle connection failures with retry logic:

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

require_once 'vendor/autoload.php';

function connectWithRetry(
    string $host,
    int $port,
    string $username,
    string $password,
    string $vhost = '/',
    int $maxRetries = 3
): StreamConnection {
    $lastException = null;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            echo "Connection attempt $attempt of $maxRetries...\n";
            
            $connection = new StreamConnection($host, $port);
            $connection->connect();
            $connection->authenticate($username, $password);
            $connection->open($vhost);
            
            echo "Connected successfully!\n";
            return $connection;
            
        } catch (TimeoutException $e) {
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

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once 'vendor/autoload.php';

function authenticateWithErrorHandling(
    StreamConnection $connection,
    string $username,
    string $password,
    string $vhost
): bool {
    try {
        $connection->authenticate($username, $password);
        $connection->open($vhost);
        return true;
    } catch (AuthenticationException $e) {
        echo "Authentication failed: Invalid username or password\n";
        return false;
    } catch (ProtocolException $e) {
        $code = $e->getResponseCode();
        
        switch ($code) {
            case ResponseCodeEnum::SASL_MECHANISM_NOT_SUPPORTED:
                echo "Authentication failed: SASL mechanism not supported\n";
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
        return false;
    }
}

// Usage
$connection = new StreamConnection('localhost', 5552);
$connection->connect();

if (!authenticateWithErrorHandling($connection, 'guest', 'wrong-password', '/')) {
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

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once 'vendor/autoload.php';

class PublisherWithErrorHandling
{
    private StreamConnection $connection;
    private int $publisherId;
    private array $failedMessages = [];
    
    public function __construct(StreamConnection $connection, int $publisherId)
    {
        $this->connection = $connection;
        $this->publisherId = $publisherId;
    }
    
    public function createPublisher(string $stream): bool
    {
        try {
            $this->connection->createPublisher($this->publisherId, $stream);
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();
            
            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
                echo "Stream '$stream' does not exist. Creating it...\n";
                try {
                    $this->connection->createStream($stream);
                    $this->connection->createPublisher($this->publisherId, $stream);
                    return true;
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
    
    public function registerCallbacks(): void
    {
        $this->connection->registerPublisher(
            $this->publisherId,
            onConfirm: function ($status) {
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
            },
            onError: function ($errors) {
                foreach ($errors as $error) {
                    echo "Publish error: " . $error->getMessage() . "\n";
                }
            }
        );
    }
    
    public function publish(string $message, int $publishingId): void
    {
        $request = new PublishRequestV1(
            publisherId: $this->publisherId,
            messages: [['publishingId' => $publishingId, 'data' => $message]]
        );
        
        $this->connection->sendMessage($request);
    }
    
    public function getFailedMessages(): array
    {
        return $this->failedMessages;
    }
}

// Usage
$connection = new StreamConnection('localhost', 5552);
$connection->connect();
$connection->authenticate('guest', 'guest');
$connection->open('/');

$publisher = new PublisherWithErrorHandling($connection, 1);

if (!$publisher->createPublisher('my-stream')) {
    echo "Failed to create publisher\n";
    exit(1);
}

$publisher->registerCallbacks();

// Publish some messages
for ($i = 1; $i <= 5; $i++) {
    $publisher->publish("Message $i", $i);
}

// Wait for confirmations
$connection->readLoop(maxFrames: 5);

// Check for failures
$failed = $publisher->getFailedMessages();
if (!empty($failed)) {
    echo "Failed to publish " . count($failed) . " messages\n";
}

$connection->close();
```

## Consumer with Offset Handling

Handle subscription errors and NO_OFFSET scenario:

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\OffsetSpecification;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once 'vendor/autoload.php';

class ConsumerWithErrorHandling
{
    private StreamConnection $connection;
    private int $subscriptionId;
    private string $stream;
    private string $reference;
    
    public function __construct(
        StreamConnection $connection,
        int $subscriptionId,
        string $stream,
        string $reference
    ) {
        $this->connection = $connection;
        $this->subscriptionId = $subscriptionId;
        $this->stream = $stream;
        $this->reference = $reference;
    }
    
    public function subscribe(OffsetSpecification $offset): bool
    {
        try {
            $this->connection->subscribe(
                $this->subscriptionId,
                $this->reference,
                $this->stream,
                $offset
            );
            echo "Subscribed to stream '{$this->stream}'\n";
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();
            
            if ($code === ResponseCodeEnum::NO_OFFSET) {
                echo "No stored offset found. Starting from beginning...\n";
                try {
                    $this->connection->subscribe(
                        $this->subscriptionId,
                        $this->reference,
                        $this->stream,
                        OffsetSpecification::first()
                    );
                    return true;
                } catch (ProtocolException $e2) {
                    echo "Failed to subscribe: " . $e2->getMessage() . "\n";
                    return false;
                }
            }
            
            if ($code === ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS) {
                echo "Subscription ID {$this->subscriptionId} already in use. Unsubscribing first...\n";
                try {
                    $this->connection->unsubscribe($this->subscriptionId);
                    $this->connection->subscribe(
                        $this->subscriptionId,
                        $this->reference,
                        $this->stream,
                        $offset
                    );
                    return true;
                } catch (ProtocolException $e2) {
                    echo "Failed to resubscribe: " . $e2->getMessage() . "\n";
                    return false;
                }
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
    
    public function unsubscribe(): void
    {
        try {
            $this->connection->unsubscribe($this->subscriptionId);
            echo "Unsubscribed successfully\n";
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();
            if ($code === ResponseCodeEnum::SUBSCRIPTION_ID_NOT_EXIST) {
                echo "Subscription already closed\n";
            } else {
                throw $e;
            }
        }
    }
}

// Usage
$connection = new StreamConnection('localhost', 5552);
$connection->connect();
$connection->authenticate('guest', 'guest');
$connection->open('/');

$consumer = new ConsumerWithErrorHandling($connection, 1, 'my-stream', 'my-consumer-group');

// Try to subscribe with stored offset, fallback to first if no offset exists
if (!$consumer->subscribe(OffsetSpecification::stored())) {
    echo "Failed to subscribe\n";
    exit(1);
}

// Consume messages...
// $connection->readLoop(maxFrames: 10);

// Clean shutdown
$consumer->unsubscribe();
$connection->close();
```

## Timeout Handling Example

Implement timeout handling with retry:

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;

require_once 'vendor/autoload.php';

function readWithTimeout(
    StreamConnection $connection,
    int $timeoutSeconds = 10,
    int $maxRetries = 2
) {
    $connection->setTimeout($timeoutSeconds);
    $lastException = null;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            return $connection->readMessage();
        } catch (TimeoutException $e) {
            $lastException = $e;
            echo "Timeout on attempt $attempt ({$timeoutSeconds}s)\n";
            
            if ($attempt < $maxRetries) {
                echo "Retrying...\n";
            }
        } catch (ConnectionException $e) {
            echo "Connection lost, attempting to reconnect...\n";
            $connection->reconnect();
        }
    }
    
    throw $lastException;
}

// Usage
$connection = new StreamConnection('localhost', 5552);
$connection->connect();
$connection->authenticate('guest', 'guest');
$connection->open('/');

try {
    $response = readWithTimeout($connection, 5, 2);
    echo "Received response\n";
} catch (TimeoutException $e) {
    echo "Operation timed out after retries\n";
    // Handle timeout - maybe continue with other work
}

$connection->close();
```

## Complete Working Example

A comprehensive example combining all patterns:

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\OffsetSpecification;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Exception\RabbitStreamException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once 'vendor/autoload.php';

class RobustStreamClient
{
    private ?StreamConnection $connection = null;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $vhost;
    
    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $vhost = '/'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->vhost = $vhost;
    }
    
    public function connect(int $maxRetries = 3): bool
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                echo "Connecting (attempt $attempt/$maxRetries)...\n";
                
                $this->connection = new StreamConnection($this->host, $this->port);
                $this->connection->connect();
                $this->connection->authenticate($this->username, $this->password);
                $this->connection->open($this->vhost);
                
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
    
    public function createPublisher(int $publisherId, string $stream): bool
    {
        try {
            $this->connection->createPublisher($publisherId, $stream);
            
            $this->connection->registerPublisher(
                $publisherId,
                onConfirm: function ($status) {
                    if ($status->isConfirmed()) {
                        echo "Message " . $status->getPublishingId() . " confirmed\n";
                    } else {
                        echo "Message " . $status->getPublishingId() . 
                             " failed (code: " . $status->getErrorCode() . ")\n";
                    }
                }
            );
            
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
    
    public function publish(int $publisherId, string $data, int $publishingId): void
    {
        $request = new PublishRequestV1(
            publisherId: $publisherId,
            messages: [['publishingId' => $publishingId, 'data' => $data]]
        );
        
        $this->connection->sendMessage($request);
    }
    
    public function subscribe(
        int $subscriptionId,
        string $stream,
        string $reference,
        OffsetSpecification $offset
    ): bool {
        try {
            $this->connection->subscribe($subscriptionId, $reference, $stream, $offset);
            echo "Subscribed to $stream\n";
            return true;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();
            
            if ($code === ResponseCodeEnum::NO_OFFSET) {
                echo "No stored offset, starting from beginning\n";
                $this->connection->subscribe(
                    $subscriptionId,
                    $reference,
                    $stream,
                    OffsetSpecification::first()
                );
                return true;
            }
            
            if ($code === ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS) {
                echo "Subscription ID in use, unsubscribing first\n";
                $this->connection->unsubscribe($subscriptionId);
                $this->connection->subscribe($subscriptionId, $reference, $stream, $offset);
                return true;
            }
            
            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
                echo "Stream does not exist: $stream\n";
                return false;
            }
            
            throw $e;
        }
    }
    
    public function close(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->close();
                echo "Connection closed\n";
            } catch (RabbitStreamException $e) {
                echo "Error during close: " . $e->getMessage() . "\n";
            }
            $this->connection = null;
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
    
    // Create publisher
    if (!$client->createPublisher(1, 'test-stream')) {
        echo "Failed to create publisher\n";
        exit(1);
    }
    
    // Publish messages
    for ($i = 1; $i <= 3; $i++) {
        $client->publish(1, "Test message $i", $i);
    }
    
    // Wait for confirmations
    $client->connection->readLoop(maxFrames: 3);
    
    // Subscribe and consume
    if ($client->subscribe(1, 'test-stream', 'test-consumer', OffsetSpecification::stored())) {
        // In real usage, you'd consume messages here
        // $client->connection->readLoop(maxFrames: 10);
    }
    
} catch (RabbitStreamException $e) {
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

use CrazyGoat\RabbitStream\Exception\RabbitStreamException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

function logException(RabbitStreamException $e, array $context = []): void
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
try {
    $connection->createPublisher(1, 'my-stream');
} catch (RabbitStreamException $e) {
    logException($e, [
        'operation' => 'createPublisher',
        'publisher_id' => 1,
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
