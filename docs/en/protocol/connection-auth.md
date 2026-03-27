# Connection & Authentication

This document provides detailed protocol reference for the connection handshake and authentication flow in RabbitMQ Streams.

## TCP Connection

RabbitMQ Stream protocol uses TCP port **5552** by default. The connection begins with a standard TCP socket establishment:

```php
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($socket, $host, $port);  // port 5552
```

## Protocol Commands

### 1. PeerProperties (0x0011)

Exchanges client and server capabilities and version information.

**Request Frame Structure:**
```
Key:        0x0011 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
Properties: Map<string, string>
```

**Request Properties (Client → Server):**
| Property | Example Value | Description |
|----------|---------------|-------------|
| `product` | `rabbit-stream-php` | Client product name |
| `version` | `1.0.0` | Client version |
| `platform` | `PHP 8.1` | Platform information |

**Response Frame Structure:**
```
Key:        0x8011 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ResponseCode: (uint16) - 0x0001 for OK
Properties: Map<string, string>
```

**Response Properties (Server → Client):**
| Property | Description |
|----------|-------------|
| `product` | Server product name (e.g., `RabbitMQ`) |
| `version` | Server version |
| `platform` | Server platform |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;

// Send
$stream->sendMessage(new PeerPropertiesToStreamBufferV1());

// Receive
$response = $stream->readMessage();
assert($response instanceof PeerPropertiesResponseV1);
$properties = $response->getProperties();
```

### 2. SaslHandshake (0x0012)

Requests available authentication mechanisms from the server.

**Request Frame Structure:**
```
Key:        0x0012 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
```

**Response Frame Structure:**
```
Key:        0x8012 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ResponseCode: (uint16) - 0x0001 for OK
Mechanisms: Array<string>
```

**Common Mechanisms:**
| Mechanism | Description |
|-----------|-------------|
| `PLAIN` | Simple username/password (most common) |
| `AMQPLAIN` | AMQP-style username/password |
| `EXTERNAL` | External authentication (e.g., TLS client certs) |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;

// Send
$stream->sendMessage(new SaslHandshakeRequestV1());

// Receive
$response = $stream->readMessage();
assert($response instanceof SaslHandshakeResponseV1);
$mechanisms = $response->getMechanisms();  // ['PLAIN', 'AMQPLAIN', ...]
```

### 3. SaslAuthenticate (0x0013)

Authenticates the client using a selected mechanism.

**Request Frame Structure:**
```
Key:        0x0013 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
Mechanism:  string (e.g., "PLAIN")
SaslData:   bytes
```

**PLAIN Mechanism Format:**

The SASL PLAIN mechanism encodes credentials as:
```
\0username\0password
```

Where:
- `\0` is a null byte (0x00)
- `username` is the user identity
- `password` is the user credential

**Example:**
```
Username: "guest"
Password: "guest"
SaslData: \0guest\0guest
         [0x00][g][u][e][s][t][0x00][g][u][e][s][t]
```

**Response Frame Structure:**
```
Key:        0x8013 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ResponseCode: (uint16)
SaslData:   bytes (optional, for challenge-response mechanisms)
```

**Response Codes:**
| Code | Name | Description |
|------|------|-------------|
| 0x0001 | OK | Authentication successful |
| 0x0008 | AUTHENTICATION_FAILURE | Invalid credentials |
| 0x0007 | SASL_MECHANISM_NOT_SUPPORTED | Mechanism not available |
| 0x0009 | SASL_ERROR | General SASL error |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Send
$stream->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));

// Receive
$response = $stream->readMessage();
assert($response instanceof SaslAuthenticateResponseV1);

// Check response code
if ($response->getResponseCode() === ResponseCodeEnum::AUTHENTICATION_FAILURE->value) {
    throw new \Exception('Authentication failed: invalid credentials');
}
```

### 4. Tune (0x0014)

Negotiates connection parameters between client and server.

**Server Request Frame Structure:**
```
Key:        0x0014 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
FrameMax:   uint32 (max frame size in bytes, 0 = unlimited)
Heartbeat:  uint32 (heartbeat interval in seconds, 0 = disabled)
```

**Client Response Frame Structure:**
```
Key:        0x8014 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ResponseCode: (uint16) - 0x0001 for OK
FrameMax:   uint32 (negotiated value)
Heartbeat:  uint32 (negotiated value)
```

**Negotiation Logic:**

The negotiated value follows these rules:
```php
function negotiatedMaxValue(int $clientValue, int $serverValue): int {
    return match (true) {
        $clientValue === 0 || $serverValue === 0 => max($clientValue, $serverValue),
        default => min($clientValue, $serverValue),
    };
}
```

| Client | Server | Negotiated | Explanation |
|--------|--------|------------|-------------|
| 0 | 1048576 | 1048576 | Use server value (0 = accept other) |
| 1048576 | 0 | 1048576 | Use client value (0 = accept other) |
| 1048576 | 2097152 | 1048576 | Use minimum (more restrictive) |
| 2097152 | 1048576 | 1048576 | Use minimum (more restrictive) |
| 0 | 0 | 0 | Both unlimited |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\TuneResponseV1;
use CrazyGoat\RabbitStream\Response\TuneRequestV1;

// Server sends TuneRequestV1 first
$tune = $stream->readMessage();
assert($tune instanceof TuneRequestV1);

// Negotiate values
$clientFrameMax = 1048576;  // 1MB
$clientHeartbeat = 60;       // 60 seconds

$negotiatedFrameMax = negotiatedMaxValue($clientFrameMax, $tune->getFrameMax());
$negotiatedHeartbeat = negotiatedMaxValue($clientHeartbeat, $tune->getHeartbeat());

// Send response
$stream->sendMessage(new TuneResponseV1($negotiatedFrameMax, $negotiatedHeartbeat));

// Apply frame size limit
if ($negotiatedFrameMax > 0) {
    $stream->setMaxFrameSize($negotiatedFrameMax);
}
```

### 5. Open (0x0015)

Selects the virtual host (namespace) for stream operations.

**Request Frame Structure:**
```
Key:        0x0015 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
VirtualHost: string (e.g., "/")
```

**Response Frame Structure:**
```
Key:        0x8015 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
ResponseCode: (uint16)
```

**Response Codes:**
| Code | Name | Description |
|------|------|-------------|
| 0x0001 | OK | Virtual host opened successfully |
| 0x000c | VIRTUAL_HOST_ACCESS_FAILURE | Access denied to vhost |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Send
$stream->sendMessage(new OpenRequestV1('/'));

// Receive
$response = $stream->readMessage();
assert($response instanceof OpenResponseV1);

// Check response code
if ($response->getResponseCode() === ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE->value) {
    throw new \Exception('Access denied to virtual host');
}
```

## Complete Handshake Example

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
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// 1. TCP Connect
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

// 4. SaslAuthenticate
$stream->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
$authResponse = $stream->readMessage();
assert($authResponse instanceof SaslAuthenticateResponseV1);
if ($authResponse->getResponseCode() !== ResponseCodeEnum::OK->value) {
    throw new \Exception('Authentication failed');
}

// 5. Tune
$tune = $stream->readMessage();
assert($tune instanceof TuneRequestV1);
$stream->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

// 6. Open
$stream->sendMessage(new OpenRequestV1('/'));
$openResponse = $stream->readMessage();
assert($openResponse instanceof OpenResponseV1);
if ($openResponse->getResponseCode() !== ResponseCodeEnum::OK->value) {
    throw new \Exception('Failed to open virtual host');
}

echo "Connection established successfully!";
```

## Protocol Key Reference

| Command | Request Key | Response Key | Description |
|---------|-------------|--------------|-------------|
| PeerProperties | 0x0011 | 0x8011 | Exchange capabilities |
| SaslHandshake | 0x0012 | 0x8012 | Get auth mechanisms |
| SaslAuthenticate | 0x0013 | 0x8013 | Authenticate |
| Tune | 0x0014 | 0x8014 | Negotiate settings |
| Open | 0x0015 | 0x8015 | Open virtual host |
| Close | 0x0016 | 0x8016 | Close connection |
| Heartbeat | 0x0017 | 0x8017 | Keepalive |

**Note:** Response keys are request keys with bit 15 set (OR with `0x8000`).

## Error Response Codes

| Code | Name | When It Occurs |
|------|------|----------------|
| 0x0008 | AUTHENTICATION_FAILURE | Invalid username/password |
| 0x000c | VIRTUAL_HOST_ACCESS_FAILURE | No access to vhost |
| 0x0007 | SASL_MECHANISM_NOT_SUPPORTED | Mechanism not available |
| 0x0009 | SASL_ERROR | General SASL error |
| 0x0001 | OK | Success |

## See Also

- [Connection Lifecycle Guide](../guide/connection-lifecycle.md) - High-level overview
- [Connection Management Commands](connection-management-commands.md) - Close, heartbeat commands
- [Low-Level Protocol Example](../examples/low-level-protocol.md) - Working code example
