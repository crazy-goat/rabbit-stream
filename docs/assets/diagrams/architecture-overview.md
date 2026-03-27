<!--
  Architecture Overview Diagrams
  Standalone ASCII diagrams for reuse in documentation
  Width: 80 characters max for terminal compatibility
-->

# Architecture Overview Diagrams

## Two-Layer Architecture

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

## Namespace Tree

```
CrazyGoat\RabbitStream\
│
├── Buffer\          # ReadBuffer, WriteBuffer, interfaces
│   ├── ReadBuffer
│   ├── WriteBuffer
│   ├── ToStreamBufferInterface
│   └── FromStreamBufferInterface
│
├── Client\          # High-level API
│   ├── Connection
│   ├── Producer
│   ├── Consumer
│   ├── Message
│   └── AmqpMessageDecoder
│
├── Contract\         # Interfaces
│   ├── CorrelationInterface
│   └── KeyVersionInterface
│
├── Enum\            # Protocol enums
│   ├── KeyEnum
│   └── ResponseCodeEnum
│
├── Request\         # Client→Server commands
│   ├── PublishRequestV1
│   ├── SubscribeRequestV1
│   ├── OpenRequestV1
│   └── ... (all *RequestV1 classes)
│
├── Response\        # Server→Client responses
│   ├── OpenResponseV1
│   ├── DeliverResponseV1
│   └── ... (all *ResponseV1 classes)
│
├── Serializer\      # Serialization strategies
│   ├── BinarySerializerInterface
│   └── PhpBinarySerializer
│
├── Trait\           # Shared traits
│   ├── CorrelationTrait
│   ├── V1Trait
│   └── CommandTrait
│
├── Util\            # Utilities
│   └── TypeCast
│
└── VO\              # Value Objects
    ├── OffsetSpec
    ├── KeyValue
    ├── Broker
    └── StreamMetadata
```

## Interface Composition

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

## Connection Handshake Flow

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

## Server-Push Frame Handling

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────┐
│   RabbitMQ      │         │ StreamConnection │         │   Callbacks  │
│   Server        │◄───────►│                  │◄───────►│              │
│                 │  TCP    │  readMessage()   │         │              │
└────────┬────────┘         └────────┬─────────┘         └─────────────┘
         │                           │
         │  0x0003 PublishConfirm    │
         │  0x0004 PublishError      │
         │  0x0008 Deliver           │
         │  0x0010 MetadataUpdate    │
         │  0x0017 Heartbeat         │
         │  0x001a ConsumerUpdate    │
         │                           │
         └───────────────────────────┘
                    Server-Push Frames
                    (no correlation ID)
```

## Frame Structure

```
┌─────────────────────────────────────────────────────────────┐
│                        Frame Structure                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────┬─────────────────────────────────────────┐ │
│  │ Size         │ uint32 (big-endian)                    │ │
│  │ (4 bytes)    │ Total frame size including Size field  │ │
│  └──────────────┴─────────────────────────────────────────┘ │
│                          │                                   │
│                          ▼                                   │
│  ┌──────────────┬─────────────────────────────────────────┐ │
│  │ Key          │ uint16 (big-endian)                      │ │
│  │ (2 bytes)    │ Command identifier (e.g., 0x0001)         │ │
│  └──────────────┴─────────────────────────────────────────┘ │
│                          │                                   │
│                          ▼                                   │
│  ┌──────────────┬─────────────────────────────────────────┐ │
│  │ Version      │ uint16 (big-endian)                      │ │
│  │ (2 bytes)    │ Protocol version (always 1 for V1)      │ │
│  └──────────────┴─────────────────────────────────────────┘ │
│                          │                                   │
│                          ▼                                   │
│  ┌──────────────┬─────────────────────────────────────────┐ │
│  │ CorrelationId│ uint32 (big-endian) - optional           │ │
│  │ (4 bytes)    │ Matches request to response             │ │
│  └──────────────┴─────────────────────────────────────────┘ │
│                          │                                   │
│                          ▼                                   │
│  ┌──────────────┬─────────────────────────────────────────┐ │
│  │ Content      │ Command-specific payload                 │ │
│  │ (variable)   │ Strings: int16-length + UTF-8            │ │
│  │              │ Bytes: int32-length + data               │ │
│  └──────────────┴─────────────────────────────────────────┘ │
│                                                              │
│  Response Key = Request Key | 0x8000                          │
│  Example: 0x0001 (Open) → 0x8001 (OpenResponse)             │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Request/Response Flow

```
┌─────────────┐         ┌──────────────┐         ┌─────────────┐
│   Client    │         │   Request    │         │   Server    │
│  Application│         │   Class      │         │  (RabbitMQ) │
└──────┬──────┘         └──────┬───────┘         └──────┬──────┘
       │                       │                        │
       │  1. Create request    │                        │
       │──────────────────────►│                        │
       │                       │                        │
       │  2. toStreamBuffer()  │                        │
       │──────────────────────►│                        │
       │                       │                        │
       │  3. WriteBuffer bytes │                        │
       │◄──────────────────────│                        │
       │                       │                        │
       │  4. Send via TCP      │                        │
       │───────────────────────────────────────────────►│
       │                       │                        │
       │                       │  5. Process            │
       │                       │◄───────────────────────│
       │                       │                        │
       │  6. Receive response  │                        │
       │◄───────────────────────────────────────────────│
       │                       │                        │
       │  7. ResponseBuilder   │                        │
       │  (fromStreamBuffer)   │                        │
       │──────────────────────►│                        │
       │                       │                        │
       │  8. Typed response    │                        │
       │◄──────────────────────│                        │
       │                       │                        │
```

## Key Classes Reference

| Class | Namespace | Purpose |
|-------|-----------|---------|
| `Connection` | `Client\` | High-level connection management |
| `Producer` | `Client\` | Message publishing with confirms |
| `Consumer` | `Client\` | Message consumption |
| `Message` | `Client\` | AMQP message representation |
| `StreamConnection` | Root | Low-level TCP socket management |
| `ResponseBuilder` | Root | Response deserialization dispatcher |
| `WriteBuffer` | `Buffer\` | Binary data serialization |
| `ReadBuffer` | `Buffer\` | Binary data deserialization |
| `KeyEnum` | `Enum\` | Protocol command codes |
| `ResponseCodeEnum` | `Enum\` | Response status codes |
