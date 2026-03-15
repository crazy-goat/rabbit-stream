# QueryOffset

**Protocol Key:** `0x000b`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Queries the stored offset for a named reference on a stream. The server responds with the current offset value.

## Protocol Structure
```
QueryOffsetRequest => Key Version CorrelationId Reference Stream
  Reference => string
  Stream => string

QueryOffsetResponse => Key Version CorrelationId ResponseCode Offset
  Offset => uint64
```

## Implementation Tasks
- [ ] Create `src/Request/QueryOffsetRequestV1.php`
- [ ] Create `src/Response/QueryOffsetResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
