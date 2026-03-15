# ResolveOffsetSpec

**Protocol Key:** `0x001f`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Client sends a Stream name, an OffsetSpecification (type + value), and a Properties map; server responds with a ResponseCode and the resolved absolute offset (type 4 + uint64 value).

## Protocol Structure
```
ResolveOffsetSpecRequest => Key Version CorrelationId Stream OffsetSpecification Properties
  Stream => string
  OffsetSpecification => OffsetType Offset
  OffsetType => uint16 // 1 (first), 2 (last), 3 (next), 4 (offset), 5 (timestamp)
  Offset => uint64 (for offset) | int64 (for timestamp)
  Properties => [Property] (key-value strings)
ResolveOffsetSpecResponse => Key Version CorrelationId ResponseCode OffsetType Offset
  OffsetType => uint16 // 4 (offset)
  Offset => uint64
```

## Implementation Tasks
- [ ] Create `src/Request/ResolveOffsetSpecRequestV1.php`
- [ ] Create `src/Response/ResolveOffsetSpecResponseV1.php`
- [ ] Reuse or extend existing OffsetSpecification value object if available
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
