# Metadata Update

**Protocol Key:** `0x0010`  
**Direction:** Server → Client  
**Expects Response:** No

## Description
A server-initiated notification sent to the client when stream metadata changes (e.g., leader election, stream deletion). The client should handle this by refreshing its metadata for the affected stream.

## Protocol Structure
```
MetadataUpdate => Key Version MetadataInfo
  MetadataInfo => Code Stream
  Code => uint16
  Stream => string
```

## Implementation Tasks
- [ ] Create `src/Response/MetadataUpdateResponseV1.php` (server-sent, no corresponding request)
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
