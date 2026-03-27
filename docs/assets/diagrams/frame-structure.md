<!--
  Protocol Frame Structure Diagram
  Shows binary layout of RabbitMQ Stream protocol frames
  Width: 80 characters max
-->

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Protocol Frame Structure                                │
└─────────────────────────────────────────────────────────────────────────────┘

Complete Frame Layout:
┌─────────────────────────────────────────────────────────────────────────────┐
│  Size (4 bytes)  │  Payload (variable)                                      │
│  uint32 BE       │  Key + Version + CorrelationId + Content                 │
└─────────────────────────────────────────────────────────────────────────────┘

Payload Structure:
┌──────────┬──────────┬─────────────────┬──────────────────────────────────────┐
│ Key      │ Version  │ CorrelationId   │ Content                              │
│ (2 bytes)│ (2 bytes)│ (4 bytes)       │ (variable)                           │
│ uint16   │ uint16   │ uint32          │ command-specific                     │
└──────────┴──────────┴─────────────────┴──────────────────────────────────────┘
```

## Response Key Formula

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Response Key = Request Key | 0x8000                                         │
│                                                                              │
│  Example:                                                                    │
│  DeclarePublisher (0x0001) ──► DeclarePublisherResponse (0x8001)              │
│  Subscribe (0x0007) ─────────► SubscribeResponse (0x8007)                     │
│  Tune (0x0014) ─────────────► TuneResponse (0x8014)                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Data Type Encodings

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ String:  [Length: uint16 BE] + [UTF-8 bytes]                                │
│                                                                              │
│  Example: "Hi"                                                               │
│  ┌─────────┬─────────┬─────────┐                                             │
│  │ 0x00 02 │ 0x48   │ 0x69   │                                             │
│  │ Length  │ 'H'    │ 'i'    │                                             │
│  └─────────┴─────────┴─────────┘                                             │
│                                                                              │
│ Bytes:   [Length: uint32 BE] + [raw bytes]  (-1 = null)                     │
│                                                                              │
│  Example: 5 bytes of data                                                      │
│  ┌─────────┬─────────┬─────────┬─────────┬─────────┬─────────┐                 │
│  │ 0x00 00 │ 0x00 05 │  data  │  data  │  data  │  data  │                 │
│  │ Length  │         │ byte 0 │ byte 1 │ byte 2 │ byte 3 │                 │
│  └─────────┴─────────┴─────────┴─────────┴─────────┴─────────┘                 │
│                                                                              │
│ Array:   [Count: uint32 BE] + [elements...]                                 │
│                                                                              │
│  Example: Array of 3 strings                                                 │
│  ┌─────────┬─────────┬─────────┬─────────┬─────────┐                          │
│  │ 0x00 00 │ 0x00 03 │ String1 │ String2 │ String3 │                          │
│  │ Count   │         │         │         │         │                          │
│  └─────────┴─────────┴─────────┴─────────┴─────────┘                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Integer Types (Big-Endian)

| Type | Size | Range |
|------|------|-------|
| `uint8` | 1 byte | 0-255 |
| `uint16` | 2 bytes | 0-65535 |
| `uint32` | 4 bytes | 0-4294967295 |
| `int8` | 1 byte | -128 to 127 |
| `int16` | 2 bytes | -32768 to 32767 |
| `int32` | 4 bytes | -2147483648 to 2147483647 |
