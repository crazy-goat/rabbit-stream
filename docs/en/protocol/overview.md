# Protocol Overview

The RabbitMQ Streams Protocol is a binary, frame-based protocol designed for high-throughput message streaming. This document provides an overview of the protocol's architecture, communication patterns, and key concepts.

## What is RabbitMQ Streams Protocol?

RabbitMQ Streams is a persistent, replicated, and scalable messaging system built into RabbitMQ. Unlike traditional AMQP 0-9-1 queues, streams provide:

- **Append-only log structure** - Messages are written sequentially and never deleted (until retention policies apply)
- **Non-destructive consumption** - Multiple consumers can read the same messages without removing them
- **High throughput** - Optimized for high-volume message streaming
- **Replay capability** - Consumers can re-read messages from any point in the stream

## Protocol Comparison

| Feature | AMQP 0-9-1 (Port 5672) | RabbitMQ Streams (Port 5552) |
|---------|------------------------|------------------------------|
| **Port** | 5672 | 5552 |
| **Message Model** | Queue-based, destructive consume | Log-based, non-destructive read |
| **Consumption** | Messages removed after ack | Messages persist, offset-based read |
| **Replay** | No | Yes - from any offset |
| **Protocol Type** | Text-based with binary frames | Pure binary, frame-based |
| **Use Case** | General messaging, RPC | Event streaming, log aggregation |

## Protocol Versions

The protocol supports versioning for forward compatibility:

- **Version 1** - Initial protocol version (current)
- **Version 2** - Extended with additional features (e.g., Deliver v2 with committed chunk ID)

Each command has a version field (uint16) in its frame. Servers and clients negotiate capabilities during the handshake.

## Frame-Based Communication

All communication happens through **frames** - discrete binary packets with the following structure:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Size (4 bytes)  │  Payload (variable)                                      │
│  uint32 BE       │  Key + Version + CorrelationId + Content                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

See [Frame Structure](./frame-structure.md) for detailed frame format documentation.

## Communication Patterns

### 1. Request/Response Pattern

Most commands follow a request/response pattern:

```
Client ──► Request (with CorrelationId) ──► Server
Client ◄── Response (matching CorrelationId) ◄── Server
```

**Key characteristics:**
- Client generates a unique `CorrelationId` (uint32) per request
- Server echoes the same `CorrelationId` in the response
- Response key = Request key | 0x8000 (sets bit 15)

**Example:**
```
DeclarePublisher Request:  0x0001
DeclarePublisher Response: 0x8001 (0x0001 | 0x8000)
```

### 2. Fire-and-Forget Pattern

Some commands don't expect a response:

```
Client ──► Request ──► Server
                (no response)
```

**Examples:**
- `Publish` (0x0002) - Messages are confirmed asynchronously via `PublishConfirm`
- `StoreOffset` (0x000a) - Offset storage is best-effort

### 3. Server-Push Pattern

Server-initiated frames without client request:

```
Server ──► Push Frame ──► Client
```

**Characteristics:**
- No `CorrelationId` (or set to 0)
- Triggered by server events (message arrival, topology changes)
- Client must handle these asynchronously

**Examples:**
- `Deliver` (0x0008) - Message delivery to consumers
- `PublishConfirm` (0x0003) - Message persistence confirmation
- `Heartbeat` (0x0017) - Connection keepalive

See [Server Push Frames](./server-push-frames.md) for complete documentation.

## Protocol Commands Index

### Connection & Authentication (5 commands)
| Key | Command | Description |
|-----|---------|-------------|
| 0x0011 | PeerProperties | Exchange capabilities |
| 0x0012 | SaslHandshake | Get auth mechanisms |
| 0x0013 | SaslAuthenticate | Authenticate |
| 0x0014 | Tune | Negotiate settings |
| 0x0015 | Open | Open virtual host |

### Publishing (6 commands)
| Key | Command | Description |
|-----|---------|-------------|
| 0x0001 | DeclarePublisher | Register publisher |
| 0x0002 | Publish | Send messages |
| 0x0003 | PublishConfirm | Server push - confirmation |
| 0x0004 | PublishError | Server push - error |
| 0x0005 | QueryPublisherSequence | Get last sequence |
| 0x0006 | DeletePublisher | Unregister publisher |

### Consuming (7 commands)
| Key | Command | Description |
|-----|---------|-------------|
| 0x0007 | Subscribe | Start consuming |
| 0x0008 | Deliver | Server push - messages |
| 0x0009 | Credit | Flow control |
| 0x000a | StoreOffset | Save position |
| 0x000b | QueryOffset | Get stored position |
| 0x000c | Unsubscribe | Stop consuming |
| 0x001a | ConsumerUpdate | Server query offset |

### Stream Management (7 commands)
| Key | Command | Description |
|-----|---------|-------------|
| 0x000d | Create | Create stream |
| 0x000e | Delete | Delete stream |
| 0x000f | Metadata | Get stream info |
| 0x0010 | MetadataUpdate | Server push - topology change |
| 0x001d | CreateSuperStream | Create super stream |
| 0x001e | DeleteSuperStream | Delete super stream |
| 0x001c | StreamStats | Get statistics |

### Routing (2 commands)
| Key | Command | Description |
|-----|---------|-------------|
| 0x0018 | Route | Resolve routing key |
| 0x0019 | Partitions | Get partition streams |

### Connection Management (4 commands)
| Key | Command | Description |
|-----|---------|-------------|
| 0x0016 | Close | Graceful shutdown |
| 0x0017 | Heartbeat | Keepalive |
| 0x001b | ExchangeCommandVersions | Negotiate versions |
| 0x001f | ResolveOffsetSpec | Resolve offset spec |

## PHP Implementation

The protocol is implemented in the `CrazyGoat\RabbitStream` namespace:

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

// Establish connection
$connection = new StreamConnection('localhost', 5552);
$connection->connect();

// All protocol commands are available as request/response classes
// See individual command documentation for details
```

### Key Classes

| Class | Purpose |
|-------|---------|
| `StreamConnection` | TCP connection management, frame I/O |
| `KeyEnum` | Protocol command keys enumeration |
| `WriteBuffer` | Frame serialization |
| `ReadBuffer` | Frame deserialization |
| `ResponseBuilder` | Response object factory |

## Official Protocol Specification

For the complete protocol specification, see:

**[RabbitMQ Streams Protocol Documentation](https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc)**

## Next Steps

- Learn about [Frame Structure](./frame-structure.md) - detailed binary format
- Explore [Publishing Commands](./publishing-commands.md) - message production
- Explore [Consuming Commands](./consuming-commands.md) - message consumption
- Explore [Stream Management](./stream-management-commands.md) - administration
- Explore [Server Push Frames](./server-push-frames.md) - async handling
