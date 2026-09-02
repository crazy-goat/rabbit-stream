# Architecture Overview

RabbitStream is organized into two distinct API layers: a **High-Level Client API** for everyday use, and a **Low-Level Protocol API** for advanced scenarios requiring direct protocol control.

## Two-Layer Architecture

### High-Level Client API (`Client/` namespace)

The high-level API provides an intuitive interface for most use cases:

- **`Connection`** — Main entry point, manages connection lifecycle, authentication, and provides factory methods for producers and consumers
- **`Producer`** — Publishing messages with automatic confirmation handling and batching support
- **`Consumer`** — Subscribing to streams and receiving messages with offset management
- **`Message`** — AMQP message representation with properties, headers, and body

**When to use:** Application development, message publishing/consuming, typical stream operations.

### Low-Level Protocol API (`Request/`, `Response/`, `Buffer/`)

The low-level API provides direct access to the RabbitMQ Streams Protocol:

- **`StreamConnection`** — TCP socket management, frame reading/writing, and server-push frame dispatch
- **`Request/*V1` classes** — Binary serialization for client→server commands (e.g., `PublishRequestV1`, `SubscribeRequestV1`)
- **`Response/*V1` classes** — Binary deserialization for server→client responses (e.g., `OpenResponseV1`, `DeliverResponseV1`)
- **`WriteBuffer`/`ReadBuffer`** — Binary data handling with big-endian encoding

**When to use:** Custom protocol implementations, debugging, extending the library, or when you need fine-grained control over the wire protocol.

## Class Hierarchy Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    User Application                      │
├─────────────────────────────────────────────────────────┤
│              High-Level Client API                       │
│  ┌────────────┐  ┌──────────┐  ┌──────────┐            │
│  │ Connection │──│ Producer │  │ Consumer │            │
│  └─────┬──────┘  └────┬─────┘  └────┬─────┘            │
│        │               │             │                   │
├────────┼───────────────┼─────────────┼───────────────────┤
│        │        Low-Level Protocol API                   │
│  ┌─────┴──────────┐  ┌┴────────────┐ ┌┴───────────────┐│
│  │StreamConnection│  │Request/*V1  │ │Response/*V1    ││
│  │  (TCP Socket)  │  │(Serialize)  │ │(Deserialize)   ││
│  └─────┬──────────┘  └┬────────────┘ └┬───────────────┘│
│        │              ┌┴──────────────┐│                 │
│        │              │ WriteBuffer   ││                 │
│        │              │ ReadBuffer    ││                 │
│        │              └───────────────┘│                 │
├────────┼───────────────────────────────┼─────────────────┤
│        │         Binary Protocol       │                 │
│        └───────── TCP Socket ──────────┘                 │
│              RabbitMQ (port 5552)                         │
└─────────────────────────────────────────────────────────┘
```

## Namespace Map

| Namespace | Description | Key Classes |
|-----------|-------------|-------------|
| `CrazyGoat\RabbitStream\Buffer\` | Binary serialization interfaces and implementations | `ReadBuffer`, `WriteBuffer`, `ToStreamBufferInterface`, `FromStreamBufferInterface` |
| `CrazyGoat\RabbitStream\Client\` | High-level API for applications | `Connection`, `Producer`, `Consumer`, `Message`, `AmqpMessageDecoder` |
| `CrazyGoat\RabbitStream\Contract\` | Interfaces defining contracts | `CorrelationInterface`, `KeyVersionInterface` |
| `CrazyGoat\RabbitStream\Enum\` | Protocol enumerations | `KeyEnum` (command keys), `ResponseCodeEnum` (status codes) |
| `CrazyGoat\RabbitStream\Request\` | Client→Server command classes | `*RequestV1` classes (e.g., `PublishRequestV1`, `SubscribeRequestV1`) |
| `CrazyGoat\RabbitStream\Response\` | Server→Client response classes | `*ResponseV1` classes (e.g., `OpenResponseV1`, `DeliverResponseV1`) |
| `CrazyGoat\RabbitStream\Serializer\` | Swappable serialization strategies | `BinarySerializerInterface`, `PhpBinarySerializer` |
| `CrazyGoat\RabbitStream\Trait\` | Shared implementation traits | `CorrelationTrait`, `V1Trait`, `CommandTrait` |
| `CrazyGoat\RabbitStream\Util\` | Utility classes | `TypeCast` |
| `CrazyGoat\RabbitStream\VO\` | Value Objects | `OffsetSpec`, `KeyValue`, `Broker`, `StreamMetadata`, etc. |

## Interface & Trait Composition

The library uses composition over inheritance. Request and response classes implement interfaces and use traits for shared functionality:

```
┌─────────────────────────────────────────────────────────────┐
│                    Interface Composition                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ToStreamBufferInterface        FromStreamBufferInterface   │
│         │                                │                   │
│         ▼                                ▼                   │
│  ┌──────────────┐              ┌──────────────┐           │
│  │ Request/*V1  │              │ Response/*V1  │           │
│  │   Classes    │              │   Classes    │           │
│  └──────┬───────┘              └──────┬───────┘           │
│         │                             │                    │
│         └──────────┬──────────────────┘                    │
│                    │                                        │
│         ┌──────────┴──────────┐                          │
│         ▼                      ▼                           │
│  CorrelationInterface    KeyVersionInterface               │
│         │                      │                           │
│         └──────────┬───────────┘                           │
│                    │                                        │
│         ┌──────────┴──────────┐                          │
│         ▼                      ▼                           │
│  CorrelationTrait        V1Trait                          │
│  (getCorrelationId)       (getVersion=1)                   │
│  (withCorrelationId)                                       │
│                                                              │
│  CommandTrait ──► (getKeyVersion, validateKeyVersion,    │
│                    assertResponseCodeOk)                    │
└─────────────────────────────────────────────────────────────┘
```

### Key Interfaces

- **`ToStreamBufferInterface`** — Implemented by request classes that can be serialized to binary
- **`FromStreamBufferInterface`** — Implemented by response classes that can be deserialized from binary
- **`CorrelationInterface`** — Provides correlation ID tracking for request/response matching
- **`KeyVersionInterface`** — Provides protocol key and version information

### Key Traits

- **`CorrelationTrait`** — Implements `CorrelationInterface` with `getCorrelationId()` and `withCorrelationId()`
- **`V1Trait`** — Implements `KeyVersionInterface` with `getVersion()` returning `1`
- **`CommandTrait`** — Provides `getKeyVersion()`, `validateKeyVersion()`, and `assertResponseCodeOk()`

## Data Flow: Connection Handshake

When establishing a connection, the following sequence occurs:

```
User Code
    │
    ▼
Connection::create()
    │
    ├── PeerPropertiesRequest ──► StreamConnection ──► TCP ──► RabbitMQ
    │                                                          │
    │   PeerPropertiesResponse ◄── StreamConnection ◄── TCP ◄──┘
    │
    ├── SaslHandshakeRequest ──► StreamConnection ──► TCP ──► RabbitMQ
    │                                                          │
    │   SaslHandshakeResponse ◄── StreamConnection ◄── TCP ◄──┘
    │
    ├── SaslAuthenticateRequest ──► StreamConnection ──► TCP ──► RabbitMQ
    │                                                             │
    │   SaslAuthenticateResponse ◄── StreamConnection ◄── TCP ◄──┘
    │
    ├── TuneRequest ──► StreamConnection ──► TCP ──► RabbitMQ
    │                                               │
    │   TuneResponse ◄── StreamConnection ◄── TCP ◄──┘
    │
    └── OpenRequest ──► StreamConnection ──► TCP ──► RabbitMQ
                                                      │
        OpenResponse ◄── StreamConnection ◄── TCP ◄───┘
```

1. **Peer Properties** — Exchange capabilities and version information
2. **SASL Handshake** — Negotiate authentication mechanism
3. **SASL Authenticate** — Perform authentication
4. **Tune** — Negotiate frame size and heartbeat interval
5. **Open** — Open the virtual host

## Server-Push Frames

Some frames are sent **Server → Client** without a correlation ID. These are handled asynchronously:

| Key | Command | Routed By | Description |
|-----|---------|-----------|-------------|
| `0x0003` | PublishConfirm | `publisherId` | Async confirmation after publish |
| `0x0004` | PublishError | `publisherId` | Async error after publish |
| `0x0008` | Deliver | `subscriptionId` | Message delivery to consumer |
| `0x0010` | MetadataUpdate | stream name | Stream topology changed |
| `0x0017` | Heartbeat | — | Must echo back immediately |
| `0x001a` | ConsumerUpdate | `subscriptionId` | Server asks for offset |

The `StreamConnection::readMessage()` method handles these transparently using an internal loop with `socket_select()`. Server-push frames are dispatched to registered callbacks, while response frames are returned to the caller.

## Detailed Documentation

For more information on specific components:

- **[Connection](../api-reference/connection.md)** — Connection management and lifecycle
- **[Producer](../api-reference/producer.md)** — Publishing messages with confirmations
- **[Consumer](../api-reference/consumer.md)** — Subscribing and receiving messages
- **[Message](../api-reference/message.md)** — AMQP message structure and properties
- **[Protocol](../protocol/frame-structure.md)** — Binary protocol details
- **[Examples](../../../examples/)** — Working code examples
