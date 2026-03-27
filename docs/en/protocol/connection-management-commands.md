# Connection Management Commands

This document provides detailed reference for connection management protocol commands in RabbitMQ Streams.

## Overview

After the initial handshake completes, the connection enters the "OPEN" state where it can send and receive stream commands. Connection management commands handle:

- Connection health (heartbeat)
- Graceful shutdown (close)
- Capability exchange (peer properties)

## Protocol Commands

### Heartbeat (0x0017)

Heartbeat frames maintain connection health by ensuring both sides are responsive. They are sent periodically based on the negotiated heartbeat interval from the Tune command.

**Frame Structure:**
```
Key:        0x0017 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - 0 for heartbeats
```

**Behavior:**

1. **Server sends heartbeat** to client at intervals (heartbeat/2 seconds)
2. **Client must echo** the heartbeat back immediately
3. **If either side** doesn't receive frames within the heartbeat interval, the connection is considered dead

**Heartbeat Echo:**
```
Server → Client: Heartbeat (0x0017)
Client → Server: Heartbeat (0x0017)  [identical frame echoed back]
```

**Automatic Handling:**

The `StreamConnection` class handles heartbeats transparently:

```php
// In readMessage() - heartbeats are automatically echoed
if ($key === KeyEnum::HEARTBEAT->value) {
    HeartbeatRequestV1::fromStreamBuffer($frame);
    $heartbeat = new HeartbeatRequestV1();
    $content = $this->serializer->serialize($heartbeat);
    $this->sendFrame(...);  // Echo back
    continue;  // Keep reading for actual response
}
```

Your application code never sees heartbeat frames - they are handled internally.

**Manual Heartbeat (if needed):**

```php
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;

// Send a heartbeat (rarely needed - automatic in readMessage)
$stream->sendMessage(new HeartbeatRequestV1());
```

**Disabling Heartbeats:**

Set heartbeat to 0 during Tune negotiation:
```php
// Client requests no heartbeat
$stream->sendMessage(new TuneResponseV1($frameMax, 0));
```

### Close (0x0016)

The Close command gracefully terminates a connection. It can be initiated by either the client or the server.

#### Client-Initiated Close

**Request Frame Structure:**
```
Key:        0x0016 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ClosingCode:   uint16 (reason code)
ClosingReason: string (human-readable reason)
```

**Response Frame Structure:**
```
Key:        0x8016 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**Common Closing Codes:**
| Code | Description |
|------|-------------|
| 0 | Normal shutdown |
| 1 | Resource error |
| 2 | Forced close by admin |
| 3 | Frame size exceeded |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;

// Send close request
$stream->sendMessage(new CloseRequestV1(0, 'Normal shutdown'));

// Wait for acknowledgment
$response = $stream->readMessage();
if ($response instanceof CloseResponseV1) {
    // Server acknowledged - safe to close socket
    $stream->close();
}
```

**High-Level Connection Close:**

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create();
// ... use connection ...

// Graceful close (sends CloseRequestV1, closes producers/consumers)
$connection->close();
```

The `Connection::close()` method performs cleanup in this order:
1. Close all consumers
2. Close all producers
3. Send `CloseRequestV1`
4. Wait for `CloseResponseV1`
5. Close the socket

#### Server-Initiated Close

The server can close the connection at any time:

**Server Close Frame:**
```
Key:        0x0016 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ClosingCode:   uint16
ClosingReason: string
```

**Client Response:**
```
Key:        0x8016 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches server's correlationId
ResponseCode:  uint16 (0x0001 = OK)
```

**Automatic Handling:**

`StreamConnection::dispatchServerPush()` handles server-initiated closes:

```php
private function dispatchServerPush(ReadBuffer $frame): void
{
    switch ($key) {
        case KeyEnum::CLOSE->value:
            // Read close details
            $frame->getUint16(); // key
            $frame->getUint16(); // version
            $correlationId = $frame->getUint32();
            $closingCode = $frame->getUint16();
            $closingReason = $frame->getString();
            
            // Log the close reason
            $this->logger->debug(sprintf(
                'Server-initiated close: code=%d, reason=%s',
                $closingCode,
                $closingReason ?? ''
            ));
            
            // Send close response with OK
            $response = (new WriteBuffer())
                ->addUInt16(KeyEnum::CLOSE_RESPONSE->value)
                ->addUInt16(1) // version
                ->addUInt32($correlationId)
                ->addUInt16(0x0001); // OK
            $this->sendFrame(...);
            
            // Close the connection
            $this->close();
            break;
    }
}
```

**Reasons for Server-Initiated Close:**
- Administrator forced close
- Resource constraints
- Protocol violations
- Authentication expiration
- Virtual host deleted

### PeerProperties (0x0011)

While primarily used during the initial handshake, PeerProperties can also be used to query server capabilities at any time.

**Request Frame:**
```
Key:        0x0011 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
Properties: Map<string, string>
```

**Response Frame:**
```
Key:        0x8011 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ResponseCode:  uint16
Properties: Map<string, string>
```

**Common Server Properties:**
| Property | Description |
|----------|-------------|
| `product` | Server product name |
| `version` | Server version |
| `platform` | Server platform |
| `capabilities` | Comma-separated feature list |

## Server-Push Frames

These frames are sent by the server without a corresponding client request:

### Heartbeat (0x0017)

Sent periodically to check connection health. Automatically echoed by client.

### Close (0x0016)

Sent when server needs to close the connection (e.g., admin action, resource limits).

### MetadataUpdate (0x0010)

Sent when stream topology changes (stream created/deleted).

### Deliver (0x0008)

Sent when messages are available for a consumer.

### PublishConfirm (0x0003)

Sent to confirm successful message publishing.

### PublishError (0x0004)

Sent when message publishing fails.

### ConsumerUpdate (0x001a)

Sent when server needs consumer offset information.

## Frame Routing

The `readMessage()` method automatically routes server-push frames:

```
Client calls readMessage() expecting a response
    ↓
Server sends a server-push frame (e.g., heartbeat)
    ↓
readMessage() detects server-push key (0x0017)
    ↓
dispatchServerPush() handles the frame
    - Heartbeat: echo back
    - Close: send response, close socket
    - Deliver: invoke subscriber callback
    - etc.
    ↓
readMessage() continues reading
    ↓
Server sends the actual response
    ↓
readMessage() returns response to caller
```

This transparent handling means your code never needs to worry about heartbeats or other async frames interfering with request/response cycles.

## Connection State Machine

```
┌─────────────┐
│   START     │
└──────┬──────┘
       │ TCP Connect
       ▼
┌─────────────┐
│  CONNECTED  │
└──────┬──────┘
       │ PeerProperties
       ▼
┌─────────────┐
│  PROPERTIES │
└──────┬──────┘
       │ SaslHandshake
       ▼
┌─────────────┐
│   SASL      │
│ HANDSHAKE   │
└──────┬──────┘
       │ SaslAuthenticate
       ▼
┌─────────────┐
│ AUTHENTICATED│
└──────┬──────┘
       │ Tune
       ▼
┌─────────────┐
│   TUNED     │
└──────┬──────┘
       │ Open
       ▼
┌─────────────┐     ┌─────────────┐
│    OPEN     │◄────│  Heartbeat  │
│   (Ready)   │     │   (async)   │
└──────┬──────┘     └─────────────┘
       │ Close
       ▼
┌─────────────┐
│   CLOSED    │
└─────────────┘
```

## Best Practices

### 1. Always Use Graceful Close

```php
// Good: Graceful close
$connection->close();

// Avoid: Just letting it drop
// (Destructor will handle it, but graceful is better)
```

### 2. Handle Server-Initiated Closes

```php
try {
    $response = $stream->readMessage();
} catch (ConnectionException $e) {
    if (strpos($e->getMessage(), 'closed by server') !== false) {
        // Server closed connection - may need to reconnect
    }
}
```

### 3. Set Appropriate Timeouts

```php
// Use reasonable timeouts for operations
$response = $stream->readMessage(timeout: 30.0);
```

### 4. Clean Up Resources

```php
try {
    // Use connection
} finally {
    // Always close, even on error
    $connection->close();
}
```

## Error Handling

### ConnectionException

Thrown for socket-level errors:
```php
use CrazyGoat\RabbitStream\Exception\ConnectionException;

try {
    $stream->connect();
} catch (ConnectionException $e) {
    echo "Connection failed: " . $e->getMessage();
}
```

### TimeoutException

Thrown when operations timeout:
```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $response = $stream->readMessage(timeout: 5.0);
} catch (TimeoutException $e) {
    echo "Operation timed out";
}
```

### Response Code Errors

Check response codes for command-specific errors:
```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

if ($response->getResponseCode() !== ResponseCodeEnum::OK->value) {
    $code = ResponseCodeEnum::fromInt($response->getResponseCode());
    echo "Error: " . $code->getMessage();
}
```

## See Also

- [Connection Lifecycle Guide](../guide/connection-lifecycle.md) - High-level overview
- [Connection & Authentication](connection-auth.md) - Handshake details
- [Low-Level Protocol Example](../examples/low-level-protocol.md) - Working code
