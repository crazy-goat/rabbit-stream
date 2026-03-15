# Deliver

**Protocol Key:** `0x0008`  
**Direction:** Server → Client  
**Expects Response:** No

## Description
Server-pushed command that delivers a chunk of messages to a subscribed consumer. Contains a binary OsirisChunk with metadata and the raw message payload.

## Protocol Structure
```
Deliver => Key Version SubscriptionId OsirisChunk
  OsirisChunk => MagicVersion ChunkType NumEntries NumRecords Timestamp Epoch ChunkFirstOffset ChunkCrc DataLength TrailerLength BloomSize Reserved Messages
  MagicVersion => int8
  ChunkType => int8 // 0: user, 1: tracking delta, 2: tracking snapshot
  NumEntries => uint16
  NumRecords => uint32
  Timestamp => int64
  Epoch => uint64
  ChunkFirstOffset => uint64
  ChunkCrc => int32
  DataLength => uint32
  TrailerLength => uint32
  BloomSize => uint8
  Reserved => uint24
  Messages => [Message]
```

## Implementation Tasks
- [ ] Create `src/Response/DeliverResponseV1.php` (server-sent, no request class needed)
- [ ] Create `src/Value/OsirisChunk.php` to represent the chunk structure
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
