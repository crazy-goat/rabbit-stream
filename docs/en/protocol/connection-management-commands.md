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
```

Heartbeat carries no payload beyond the key and version — in particular, **no
CorrelationId**.

**Behavior:**

1. **Server sends heartbeat** to client at intervals (heartbeat/2 seconds)
1. **Server sends heartbeat** to client only when it sent no other data in the last heartbeat interval
2. **Client must echo** the heartbeat back immediately
3. **The client closes the connection** after two heartbeat intervals without inbound data.

Every byte the client receives counts as inbound data. This includes data that waits unread in the socket. The client checks this rule only inside a library call. Between library calls, the client does not check it.

**Heartbeat Echo:**
```
Server → Client: Heartbeat (0x0017)
Client → Server: Heartbeat (0x0017)  [identical frame echoed back]
```

**Automatic Handling:**

The `StreamConnection` class handles heartbeats transparently. When `readMessage()`,
`request()` or `readLoop()` reads a Heartbeat frame, `dispatchServerPush()` passes it to
an internal handler. The handler echoes a Heartbeat frame back to the server and then
runs the callback registered with `onHeartbeat()`, if any.

The client also sends its own heartbeats. While a library call waits for data, the client
sends a Heartbeat frame when it wrote nothing to the server for one heartbeat interval. It
closes the connection and throws `ConnectionException` after two heartbeat intervals
without inbound data.

Your application code never sees heartbeat frames - they are handled internally. To
observe them, register a callback (it runs after each echo):

```php
$stream->onHeartbeat(function (): void {
    echo "Heartbeat echoed\n";
});

$stream->readLoop();
```

**Manual Heartbeat (if needed):**

```php
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;

// Send a heartbeat (rarely needed - the client sends and echoes heartbeats automatically)
$stream->sendMessage(new HeartbeatRequestV1());
```
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

`StreamConnection` handles server-initiated closes internally. When `readMessage()`,
`request()` or `readLoop()` reads a Close frame, `dispatchServerPush()` passes it to an
internal handler. The handler reads the correlation ID, the closing code and the closing
reason, and stores the code and the reason. It sends a `CloseResponse` (`0x8016`) with the
server's correlation ID and response code `0x0001` (OK), and then closes the socket.

After that, `readMessage()` and `request()` throw a `ConnectionException`, and `readLoop()`
returns. The exception gives the closing code and the reason through
`getServerCloseCode()` and `getServerCloseReason()` (see "Handle Server-Initiated Closes"
under Best Practices below).

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

### ExchangeCommandVersions (0x001b)

Negotiates supported protocol versions for commands between client and server.

**Request Frame Structure:**
```
Key:        0x001b (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
commands[]:    Array<CommandVersion>
```

**CommandVersion Structure:**
```
key:        uint16 (command key)
minVersion: uint16 (minimum supported version)
maxVersion: uint16 (maximum supported version)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `commands` | CommandVersion[] | List of commands with version ranges |

**Response Frame Structure:**
```
Key:        0x801b (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
commands[]:    Array<CommandVersion>
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `commands` | CommandVersion[] | Server's supported versions |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\VO\CommandVersion;

// Send the versions this client implements. Only advertise commands with more
// than one version: a v1-only command is covered by the baseline, and an
// unknown command key terminates the broker's stream connection process.
$stream->sendMessage(new ExchangeCommandVersionsRequestV1(
    commands: [
        new CommandVersion(key: 0x0002, minVersion: 1, maxVersion: 2),  // Publish v1-v2
    ]
));

$response = $stream->readMessage();
assert($response instanceof ExchangeCommandVersionsResponseV1);

// Check negotiated versions
foreach ($response->getCommands() as $cmd) {
    echo "Command 0x{$cmd->getKey()}: versions {$cmd->getMinVersion()}-{$cmd->getMaxVersion()}\n";
}
```

### ResolveOffsetSpec (0x001f)

Resolves an offset specification to a concrete offset value.

**Request Frame Structure:**
```
Key:            0x001f (uint16)
Version:        1 (uint16)
CorrelationId:  (uint32)
stream:         string
offsetSpec:     OffsetSpecification (uint16 type, then offset value if the type needs one)
properties:     int32 count, then count pairs of Key (string) and Value (string)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `stream` | string | Stream name |
| `offsetSpec` | OffsetSpec | Offset specification to resolve |
| `properties` | map of string to string | Request properties. The client sends an empty map by default. |

The client sends the properties in the order of the PHP array. The supported properties are `filter.` (prefix for filter values, for example `filter.0`), `match-unfiltered` and `chunk_selector`.

**Response Frame Structure:**
```
Key:            0x801f (uint16)
Version:        1 (uint16)
CorrelationId:  (uint32) - matches request
ResponseCode:   uint16 (0x0001 = OK)
OffsetType:     uint16 (always 4 = offset)
offset:         uint64 (resolved offset value)
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `offset` | uint64 | Concrete offset value |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Resolve "last" offset to concrete value
$stream->sendMessage(new ResolveOffsetSpecRequestV1(
    stream: 'my-stream',
    offsetSpec: OffsetSpec::last()
));

$response = $stream->readMessage();
assert($response instanceof ResolveOffsetSpecResponseV1);
$concreteOffset = $response->getOffset();
echo "Resolved offset: $concreteOffset\n";
```

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

Fragment: assumes `$stream` is connected and a request has been sent, with its response pending.

```php
try {
    $response = $stream->readMessage();
} catch (ConnectionException $e) {
    if (strpos($e->getMessage(), 'closed by server') !== false) {
        // Server closed connection - may need to reconnect
        $code = $e->getServerCloseCode();   // ClosingCode from the Close frame
        $reason = $e->getServerCloseReason(); // ClosingReason from the Close frame
    }
}
```

### 3. Set Appropriate Timeouts

Fragment: assumes `$stream` is connected and a request has been sent, with its response pending.

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

Thrown when operations timeout. This fragment assumes `$stream` is connected and a request has been sent, with its response pending.
```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $response = $stream->readMessage(timeout: 5.0);
} catch (TimeoutException $e) {
    echo "Operation timed out";
}
```

### Response Code Errors

A non-OK response code is asserted during deserialization and surfaces as a
`ProtocolException`; inspect the code via the caught exception. This fragment assumes
`$stream` is connected and a request has been sent, with its response pending.
```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;

try {
    $response = $stream->readMessage();
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    echo "Error: " . ($code?->getMessage() ?? $e->getMessage());
}
```

## See Also

- [Connection Lifecycle Guide](../guide/connection-lifecycle.md) - High-level overview
- [Connection & Authentication](connection-auth.md) - Handshake details
- [Low-Level Protocol Example](../examples/low-level-protocol.md) - Working code
