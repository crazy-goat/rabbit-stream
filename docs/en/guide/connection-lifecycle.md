# Connection Lifecycle

This guide covers the complete lifecycle of a RabbitMQ Stream connection, from initial TCP connection to graceful shutdown.

## Overview

The RabbitMQ Stream protocol requires a 5-step handshake before a connection is ready for use. This handshake establishes capabilities, authenticates the client, negotiates connection parameters, and selects a virtual host.

## Connection Handshake Sequence

The connection handshake follows this exact sequence:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Connection Handshake Sequence                             │
└─────────────────────────────────────────────────────────────────────────────┘

     Client                                              Server
       │                                                   │
       │  TCP Connect (port 5552)                          │
       │ ═══════════════════════════════════════════════►│
       │                                                   │
       │  1. PeerProperties (0x0011)                       │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     PeerPropertiesResponse (0x8011)               │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  2. SaslHandshake (0x0012)                        │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     SaslHandshakeResponse (0x8012)                │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  3. SaslAuthenticate (0x0013)                       │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     SaslAuthenticateResponse (0x8013)             │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  4. Tune (0x0014)                                   │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     TuneResponse (0x8014)                         │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  5. Open (0x0015)                                   │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     OpenResponse (0x8015)                         │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       ▼                                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Connection Established - Ready for Use                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Step-by-Step Explanation

### 1. PeerProperties (0x0011 / 0x8011)

The first step exchanges client and server capabilities. The client sends its properties (like product name, version, platform), and the server responds with its capabilities.

**Purpose:**
- Exchange version information
- Advertise supported features
- Establish protocol compatibility

**Key/Response:** `0x0011` / `0x8011`

### 2. SaslHandshake (0x0012 / 0x8012)

The client requests available authentication mechanisms from the server. The server responds with a list of supported SASL mechanisms.

**Purpose:**
- Discover available authentication methods
- Common mechanisms: PLAIN, AMQPLAIN, EXTERNAL

**Key/Response:** `0x0012` / `0x8012`

### 3. SaslAuthenticate (0x0013 / 0x8013)

The client sends credentials using one of the supported mechanisms. For PLAIN authentication, the format is:

```
\0username\0password
```

**Purpose:**
- Authenticate the client with username/password
- Verify access permissions

**Key/Response:** `0x0013` / `0x8013`

### 4. Tune (0x0014 / 0x8014)

Both client and server negotiate connection parameters:
- **frameMax**: Maximum frame size in bytes (0 = unlimited)
- **heartbeat**: Heartbeat interval in seconds (0 = disabled)

The negotiated value is the **minimum** of client and server proposals:
- If either side proposes 0, use the non-zero value
- If both propose 0, result is 0 (unlimited/no heartbeat)
- Otherwise, use `min(clientValue, serverValue)`

**Purpose:**
- Negotiate maximum frame size
- Establish heartbeat interval for connection health

**Key/Response:** `0x0014` / `0x8014`

### 5. Open (0x0015 / 0x8015)

The final step selects the virtual host (namespace) to use. The default virtual host is `"/"`.

**Purpose:**
- Select virtual host for stream operations
- Verify access to the requested vhost

**Key/Response:** `0x0015` / `0x8015`

## High-Level Connection (Connection::create)

For most use cases, use the high-level `Connection::create()` method which handles the entire handshake automatically:

```php
use CrazyGoat\RabbitStream\Client\Connection;

// Simple connection with defaults
$connection = Connection::create();

// Full connection with all parameters
$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    vhost: '/',
    requestedFrameMax: 1048576,  // 1MB max frame size
    requestedHeartbeat: 60       // 60 second heartbeat
);
```

The `Connection::create()` method:
1. Establishes TCP connection to port 5552
2. Performs all 5 handshake steps automatically
3. Negotiates frameMax and heartbeat values
4. Returns a ready-to-use Connection object

## Low-Level Connection (StreamConnection)

For advanced use cases or protocol debugging, use the low-level `StreamConnection` class to perform the handshake manually:

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\TuneResponseV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;

// 1. Create and connect
$stream = new StreamConnection('127.0.0.1', 5552);
$stream->connect();

// 2. PeerProperties
$stream->sendMessage(new PeerPropertiesToStreamBufferV1());
$peerResponse = $stream->readMessage();
assert($peerResponse instanceof PeerPropertiesResponseV1);

// 3. SaslHandshake
$stream->sendMessage(new SaslHandshakeRequestV1());
$handshakeResponse = $stream->readMessage();
assert($handshakeResponse instanceof SaslHandshakeResponseV1);

// Verify PLAIN is supported
$mechanisms = $handshakeResponse->getMechanisms();
if (!in_array('PLAIN', $mechanisms, true)) {
    throw new \Exception('PLAIN mechanism not supported');
}

// 4. SaslAuthenticate
$stream->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
$authResponse = $stream->readMessage();
assert($authResponse instanceof SaslAuthenticateResponseV1);

// 5. Tune (server sends TuneRequestV1 first)
$tune = $stream->readMessage();
assert($tune instanceof TuneRequestV1);

// Send TuneResponse with negotiated values
$negotiatedFrameMax = min($tune->getFrameMax(), 1048576);
$negotiatedHeartbeat = min($tune->getHeartbeat(), 60);
$stream->sendMessage(new TuneResponseV1($negotiatedFrameMax, $negotiatedHeartbeat));

if ($negotiatedFrameMax > 0) {
    $stream->setMaxFrameSize($negotiatedFrameMax);
}

// 6. Open
$stream->sendMessage(new OpenRequestV1('/'));
$openResponse = $stream->readMessage();
assert($openResponse instanceof OpenResponseV1);

// Connection is now ready!
```

**When to use low-level API:**
- Protocol debugging or learning
- Custom authentication flows
- Fine-grained control over negotiation
- Implementing non-standard connection logic

## Graceful Close

### Client-Initiated Close

To gracefully close a connection, send a `CloseRequestV1` and wait for the server's acknowledgment:

```php
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;

// Send close request with code and reason
$stream->sendMessage(new CloseRequestV1(0, 'Normal shutdown'));
$response = $stream->readMessage();

if ($response instanceof CloseResponseV1) {
    // Server acknowledged close
    $stream->close();  // Close the socket
}
```

The high-level `Connection` class handles this automatically:

```php
$connection->close();  // Closes producers, consumers, sends CloseRequestV1
```

### Server-Initiated Close

The server can initiate a close at any time by sending a `Close` frame (key `0x0016`). The client must:
1. Read the close request (contains correlationId, code, reason)
2. Send a `CloseResponseV1` with OK status
3. Close the socket

This is handled automatically by `StreamConnection::dispatchServerPush()` when using `readMessage()` or `readLoop()`.

### Destructor Auto-Cleanup

Both `Connection` and `StreamConnection` have destructors that automatically close the connection if not already closed:

```php
// In Connection::__destruct()
if (!$this->closed) {
    try {
        $this->close();
    } catch (\Throwable $e) {
        // Log error but don't throw from destructor
    }
}

// In StreamConnection::__destruct()
$this->close();
```

**Best practices for cleanup:**
1. Always call `close()` explicitly when done
2. Close producers and consumers before closing the connection
3. Use try/finally to ensure cleanup happens even on errors

## Error Scenarios

### Authentication Failure (0x08)

Occurs when credentials are invalid:

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// After SaslAuthenticate, check response code
if ($response->getResponseCode() === ResponseCodeEnum::AUTHENTICATION_FAILURE->value) {
    throw new \Exception('Invalid username or password');
}
```

**Response Code:** `0x08` (AUTHENTICATION_FAILURE)

### Virtual Host Access Failure (0x0c)

Occurs when the user doesn't have access to the requested virtual host:

```php
// After Open, check response code
if ($response->getResponseCode() === ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE->value) {
    throw new \Exception('Access denied to virtual host');
}
```

**Response Code:** `0x0c` (VIRTUAL_HOST_ACCESS_FAILURE)

### Connection Timeout

Socket operations can timeout. The library throws `TimeoutException`:

```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $response = $stream->readMessage(timeout: 30.0);
} catch (TimeoutException $e) {
    // Handle timeout - server may be slow or unresponsive
}
```

### Frame Too Large (0x0e)

Occurs when a received frame exceeds the negotiated `frameMax`:

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Server may send this error code
if ($response->getResponseCode() === ResponseCodeEnum::FRAME_TOO_LARGE->value) {
    throw new \Exception('Frame size exceeded maximum allowed');
}
```

**Response Code:** `0x0e` (FRAME_TOO_LARGE)

### Socket Errors

Connection errors throw `ConnectionException`:

```php
use CrazyGoat\RabbitStream\Exception\ConnectionException;

try {
    $stream->connect();
} catch (ConnectionException $e) {
    // Handle connection failure
    echo $e->getMessage();  // "Cannot connect to 127.0.0.1:5552: ..."
}
```

## Server-Push Frames

During the connection lifecycle, the server may send unsolicited frames:

### Heartbeat (0x0017)

The server sends periodic heartbeat frames to check connection health. The client must echo them back immediately:

```
Server → Client: Heartbeat (0x0017)
Client → Server: Heartbeat (0x0017)  [echo]
```

This is handled **transparently** by `StreamConnection::readMessage()` - you never see heartbeat frames in your code.

### Transparent Handling

The `readMessage()` method uses an internal loop to handle server-push frames:

```
readMessage():
    while (true):
        wait for data via socket_select()
        frame = readFrame()
        if frame.key is server-push (0x0003/0x0004/0x0008/0x0010/0x0016/0x0017/0x001a):
            dispatch(frame) → handle heartbeat, close, etc.
            continue        → keep reading
        else:
            return frame    → give caller the response
```

This means:
- Heartbeats are automatically echoed
- Server-initiated closes are handled
- Publish confirms/errors are dispatched to callbacks
- Your code only sees the responses it expects

## Connection State Summary

| State | Description | Transitions |
|-------|-------------|-------------|
| START | Initial state | → CONNECTED (TCP connect) |
| CONNECTED | TCP established | → PROPERTIES (PeerProperties) |
| PROPERTIES | Capabilities exchanged | → SASL_HANDSHAKE (SaslHandshake) |
| SASL_HANDSHAKE | Mechanisms known | → AUTHENTICATED (SaslAuthenticate) |
| AUTHENTICATED | User authenticated | → TUNED (Tune) |
| TUNED | Parameters negotiated | → OPEN (Open) |
| OPEN | Ready for use | → CLOSED (Close) |
| CLOSED | Connection terminated | - |

## See Also

- [Connection & Authentication Protocol](connection-auth.md) - Detailed protocol reference
- [Connection Management Commands](connection-management-commands.md) - Command reference
- [Low-Level Protocol Example](../examples/low-level-protocol.md) - Working code example
