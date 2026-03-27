# Error Handling

RabbitStream provides comprehensive error handling through response codes, a structured exception hierarchy, and clear patterns for handling failures. This guide covers all aspects of error handling in the library.

## Overview

The RabbitMQ Stream protocol communicates errors through:

1. **Response Codes** - Numeric codes in every server response indicating success or failure
2. **Exceptions** - PHP exceptions thrown for protocol errors, connection failures, and invalid operations
3. **Confirmation Status** - Asynchronous publish confirmations that may contain error information

Understanding these mechanisms is essential for building robust stream applications.

## Response Code Reference

Every response from the server includes a response code. The `ResponseCodeEnum` defines all 19 possible codes:

| Code | Name | Hex | Description | When It Occurs |
|------|------|-----|-------------|----------------|
| 1 | `OK` | 0x01 | Operation completed successfully | Normal successful response |
| 2 | `STREAM_NOT_EXIST` | 0x02 | The referenced stream does not exist | Creating publisher/consumer for non-existent stream, deleting non-existent stream |
| 3 | `SUBSCRIPTION_ID_ALREADY_EXISTS` | 0x03 | Subscription ID is already in use | Subscribing with an ID that's already active |
| 4 | `SUBSCRIPTION_ID_NOT_EXIST` | 0x04 | Subscription ID does not exist | Committing offset or unsubscribing with invalid ID |
| 5 | `STREAM_ALREADY_EXISTS` | 0x05 | Stream already exists | Creating a stream that already exists |
| 6 | `STREAM_NOT_AVAILABLE` | 0x06 | Stream is temporarily unavailable | Stream leader is down, partition is being reassigned |
| 7 | `SASL_MECHANISM_NOT_SUPPORTED` | 0x07 | Authentication mechanism not supported | Requesting SASL mechanism server doesn't support |
| 8 | `AUTHENTICATION_FAILURE` | 0x08 | Authentication failed | Invalid credentials |
| 9 | `SASL_ERROR` | 0x09 | Generic SASL error | Authentication protocol error |
| 10 | `SASL_CHALLENGE` | 0x0a | SASL challenge received | Part of multi-step authentication |
| 11 | `SASL_AUTHENTICATION_FAILURE_LOOPBACK` | 0x0b | Loopback authentication failed | Internal authentication error |
| 12 | `VIRTUAL_HOST_ACCESS_FAILURE` | 0x0c | Cannot access virtual host | User lacks permissions for vhost |
| 13 | `UNKNOWN_FRAME` | 0x0d | Unknown command frame | Protocol mismatch or corruption |
| 14 | `FRAME_TOO_LARGE` | 0x0e | Frame exceeds maximum size | Sending oversized messages |
| 15 | `INTERNAL_ERROR` | 0x0f | Server internal error | RabbitMQ server error |
| 16 | `ACCESS_REFUSED` | 0x10 | Access refused | Insufficient permissions for operation |
| 17 | `PRECONDITION_FAILED` | 0x11 | Precondition not met | Stream parameters don't match (e.g., max-age differs) |
| 18 | `PUBLISHER_NOT_EXIST` | 0x12 | Publisher ID does not exist | Publishing with invalid publisher ID |
| 19 | `NO_OFFSET` | 0x13 | No offset stored for consumer | First-time consumer with no stored offset |

### Working with Response Codes

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Check if a code represents success
$code = ResponseCodeEnum::OK;
if ($code->isSuccess()) {
    echo "Operation successful!";
}

// Check if a code represents an error
$code = ResponseCodeEnum::STREAM_NOT_EXIST;
if ($code->isError()) {
    echo "Error: " . $code->getMessage(); // "Stream does not exist"
}

// Convert integer to enum
$code = ResponseCodeEnum::fromInt(0x02); // Returns STREAM_NOT_EXIST
```

## Exception Hierarchy

RabbitStream uses a structured exception hierarchy that allows precise error handling:

```
RabbitStreamExceptionInterface (interface)
└── RabbitStreamException (base RuntimeException)
    ├── ProtocolException (has ResponseCodeEnum)
    │   ├── AuthenticationException
    │   └── UnexpectedResponseException (has expected/actual class info)
    ├── ConnectionException
    │   └── TimeoutException
    ├── DeserializationException
    └── InvalidArgumentException (extends \InvalidArgumentException)
```

### Base Exception: `RabbitStreamException`

The root of all library-specific exceptions. Catching this handles any RabbitStream error:

```php
use CrazyGoat\RabbitStream\Exception\RabbitStreamException;

try {
    $connection->sendMessage($request);
} catch (RabbitStreamException $e) {
    // Handle any RabbitStream error
    error_log("RabbitStream error: " . $e->getMessage());
}
```

### ProtocolException

Thrown when the server returns an error response code. Contains the response code for programmatic handling:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    $connection->createPublisher($publisherId, 'non-existent-stream');
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    
    if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
        // Create the stream first
        $connection->createStream('non-existent-stream');
    } elseif ($code === ResponseCodeEnum::ACCESS_REFUSED) {
        // Log permission error
        error_log("Permission denied for stream");
    }
}
```

#### AuthenticationException

Specialized `ProtocolException` for authentication failures:

```php
use CrazyGoat\RabbitStream\Exception\AuthenticationException;

try {
    $connection->authenticate($username, $password);
} catch (AuthenticationException $e) {
    // Handle invalid credentials
    echo "Authentication failed: " . $e->getMessage();
}
```

#### UnexpectedResponseException

Thrown when the response type doesn't match expectations. Useful for debugging protocol issues:

```php
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;

try {
    $response = $connection->sendMessage($request);
} catch (UnexpectedResponseException $e) {
    echo "Expected: " . $e->getExpectedClass();
    echo "Got: " . $e->getActualClass();
}
```

### ConnectionException

Thrown for TCP connection failures, socket errors, and connection state issues:

```php
use CrazyGoat\RabbitStream\Exception\ConnectionException;

try {
    $connection = new StreamConnection('invalid.host', 5552);
    $connection->connect();
} catch (ConnectionException $e) {
    // Handle connection failure
    echo "Cannot connect: " . $e->getMessage();
}
```

#### TimeoutException

Specialized `ConnectionException` for timeout scenarios:

```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $connection->setTimeout(5); // 5 seconds
    $response = $connection->readMessage();
} catch (TimeoutException $e) {
    // Handle timeout - maybe retry or reconnect
    echo "Operation timed out";
}
```

### DeserializationException

Thrown when frame parsing fails, indicating protocol corruption or version mismatch:

```php
use CrazyGoat\RabbitStream\Exception\DeserializationException;

try {
    $response = $connection->readMessage();
} catch (DeserializationException $e) {
    // Protocol error - connection may be corrupted
    error_log("Protocol deserialization error: " . $e->getMessage());
    $connection->close();
}
```

### InvalidArgumentException

Thrown for invalid method arguments:

```php
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

try {
    $connection->subscribe($subscriptionId, '', 'my-stream'); // Empty reference name
} catch (InvalidArgumentException $e) {
    echo "Invalid argument: " . $e->getMessage();
}
```

## Common Error Scenarios

### Connection Establishment Errors

Errors during the 5-step connection handshake:

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function connectWithRetry(string $host, int $port, string $user, string $pass): ?StreamConnection
{
    $maxRetries = 3;
    $retryDelay = 1; // seconds
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $connection = new StreamConnection($host, $port);
            $connection->connect();
            $connection->authenticate($user, $pass);
            $connection->open('/');
            return $connection;
        } catch (ConnectionException $e) {
            // Network error - retryable
            if ($attempt < $maxRetries) {
                sleep($retryDelay);
                continue;
            }
            throw $e;
        } catch (AuthenticationException $e) {
            // Auth failure - not retryable
            error_log("Authentication failed: " . $e->getMessage());
            return null;
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();
            if ($code === ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE) {
                error_log("Cannot access virtual host");
                return null;
            }
            throw $e;
        }
    }
    
    return null;
}
```

### Authentication Failures

Handle various authentication scenarios:

```php
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    $connection->authenticate($username, $password);
} catch (AuthenticationException $e) {
    // Invalid credentials
    echo "Login failed. Please check your username and password.";
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    
    switch ($code) {
        case ResponseCodeEnum::SASL_MECHANISM_NOT_SUPPORTED:
            echo "Authentication mechanism not supported by server";
            break;
        case ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE:
            echo "User cannot access this virtual host";
            break;
        case ResponseCodeEnum::ACCESS_REFUSED:
            echo "Access refused - check user permissions";
            break;
        default:
            throw $e;
    }
}
```

### Stream Operation Errors

Handle stream creation and deletion errors:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function ensureStreamExists($connection, string $streamName): void
{
    try {
        $connection->createStream($streamName);
    } catch (ProtocolException $e) {
        $code = $e->getResponseCode();
        
        if ($code === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
            // Stream already exists - this is fine
            return;
        }
        
        if ($code === ResponseCodeEnum::PRECONDITION_FAILED) {
            // Stream exists with different parameters
            error_log("Stream exists with different configuration");
            throw $e;
        }
        
        if ($code === ResponseCodeEnum::ACCESS_REFUSED) {
            error_log("Permission denied creating stream");
            throw $e;
        }
        
        throw $e;
    }
}
```

### Publishing Errors

Handle publisher creation and publish errors:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Creating a publisher
try {
    $connection->createPublisher($publisherId, 'my-stream');
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    
    if ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
        // Create stream first
        $connection->createStream('my-stream');
        $connection->createPublisher($publisherId, 'my-stream');
    } elseif ($code === ResponseCodeEnum::ACCESS_REFUSED) {
        error_log("No permission to publish to this stream");
    }
}

// Publishing (async errors via callbacks)
$connection->registerPublisher($publisherId, 
    onConfirm: function ($status) {
        if (!$status->isConfirmed()) {
            $errorCode = $status->getErrorCode();
            $publishingId = $status->getPublishingId();
            error_log("Publish $publishingId failed with code: $errorCode");
        }
    },
    onError: function ($errors) {
        foreach ($errors as $error) {
            error_log("Publish error: " . $error->getMessage());
        }
    }
);
```

### Consuming Errors

Handle subscription and consumer errors:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Subscribing to a stream
try {
    $connection->subscribe(
        subscriptionId: 1,
        referenceName: 'my-consumer-group',
        streamName: 'my-stream',
        offsetType: OffsetSpecification::first()
    );
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    
    if ($code === ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS) {
        // Unsubscribe first, then retry
        $connection->unsubscribe(1);
        $connection->subscribe(1, 'my-consumer-group', 'my-stream', OffsetSpecification::first());
    } elseif ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
        error_log("Cannot subscribe - stream does not exist");
    } elseif ($code === ResponseCodeEnum::ACCESS_REFUSED) {
        error_log("No permission to consume from this stream");
    }
}

// Handling NO_OFFSET on first consumer run
if ($code === ResponseCodeEnum::NO_OFFSET) {
    // First time consuming - start from beginning or latest
    $connection->subscribe(
        subscriptionId: 1,
        referenceName: 'my-consumer-group',
        streamName: 'my-stream',
        offsetType: OffsetSpecification::first() // or ::last()
    );
}
```

### Timeout Handling

Handle operation timeouts gracefully:

```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;

$connection->setTimeout(10); // 10 second timeout

try {
    $response = $connection->readMessage();
} catch (TimeoutException $e) {
    // Operation took too long
    // Decide: retry, reconnect, or fail
    
    // Option 1: Retry once
    try {
        $response = $connection->readMessage();
    } catch (TimeoutException $e) {
        // Second timeout - give up
        throw new \RuntimeException("Operation timed out twice");
    }
} catch (ConnectionException $e) {
    // Connection lost
    $connection->reconnect();
}
```

## Best Practices

### 1. Catch Specific Exceptions First

Always catch most specific exceptions before general ones:

```php
// Good
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\RabbitStreamException;

try {
    // operation
} catch (TimeoutException $e) {
    // Handle timeout specifically
} catch (ConnectionException $e) {
    // Handle connection error
} catch (RabbitStreamException $e) {
    // Handle any other RabbitStream error
}
```

### 2. Check ConfirmationStatus in Publish Callbacks

Always verify publish confirmations:

```php
$connection->registerPublisher($publisherId,
    onConfirm: function ($status) {
        if (!$status->isConfirmed()) {
            // Handle publish failure
            $errorCode = $status->getErrorCode();
            $publishingId = $status->getPublishingId();
            
            // Log or retry
            error_log("Message $publishingId failed: code $errorCode");
        }
    }
);
```

### 3. Handle NO_OFFSET Gracefully

First-time consumers need special handling:

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\OffsetSpecification;

function subscribeWithOffsetFallback($connection, $subscriptionId, $reference, $stream, $preferredOffset)
{
    try {
        $connection->subscribe($subscriptionId, $reference, $stream, $preferredOffset);
    } catch (ProtocolException $e) {
        if ($e->getResponseCode() === ResponseCodeEnum::NO_OFFSET) {
            // No stored offset - use default
            $connection->subscribe(
                $subscriptionId, 
                $reference, 
                $stream, 
                OffsetSpecification::first()
            );
        } else {
            throw $e;
        }
    }
}
```

### 4. Implement Graceful Shutdown

Always close connections properly:

```php
function shutdown($connection, $publisherId = null, $subscriptionId = null)
{
    try {
        if ($subscriptionId !== null) {
            $connection->unsubscribe($subscriptionId);
        }
        if ($publisherId !== null) {
            $connection->deletePublisher($publisherId);
        }
        $connection->close();
    } catch (RabbitStreamException $e) {
        // Log but don't throw - we're shutting down anyway
        error_log("Error during shutdown: " . $e->getMessage());
    }
}
```

### 5. Log Exception Details

Include response codes and context in logs:

```php
try {
    $connection->createPublisher($id, $stream);
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    $codeName = $code ? $code->name : 'UNKNOWN';
    $codeValue = $code ? $code->value : 'N/A';
    
    error_log(sprintf(
        "Failed to create publisher %d for stream %s: %s (code: %d)",
        $id,
        $stream,
        $codeName,
        $codeValue
    ));
}
```

### 6. Use Retry with Exponential Backoff

For transient errors, implement retry logic:

```php
function withRetry(callable $operation, int $maxRetries = 3, int $baseDelay = 1)
{
    $lastException = null;
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            return $operation();
        } catch (TimeoutException $e) {
            $lastException = $e;
            // Exponential backoff
            usleep($baseDelay * 1000000 * (2 ** $i));
        } catch (ConnectionException $e) {
            $lastException = $e;
            // Reconnect then retry
            $connection->reconnect();
        }
    }
    
    throw $lastException;
}
```

### 7. Distinguish Retryable vs Non-Retryable Errors

Some errors should not be retried:

```php
function isRetryableError(RabbitStreamException $e): bool
{
    if ($e instanceof AuthenticationException) {
        return false; // Auth failure won't fix itself
    }
    
    if ($e instanceof ProtocolException) {
        $code = $e->getResponseCode();
        
        // Non-retryable codes
        $nonRetryable = [
            ResponseCodeEnum::AUTHENTICATION_FAILURE,
            ResponseCodeEnum::ACCESS_REFUSED,
            ResponseCodeEnum::STREAM_ALREADY_EXISTS,
            ResponseCodeEnum::PRECONDITION_FAILED,
        ];
        
        if (in_array($code, $nonRetryable, true)) {
            return false;
        }
    }
    
    return true;
}
```

## Related Documentation

- [Connection Lifecycle](connection-lifecycle.md) - Understanding connection states and errors
- [Publishing](publishing.md) - Publisher creation and publish confirmations
- [Consuming](consuming.md) - Subscription management and offset handling
- [Error Handling Patterns Example](../examples/error-handling-patterns.md) - Complete working examples
