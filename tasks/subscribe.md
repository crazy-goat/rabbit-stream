# Subscribe

**Protocol Key:** `0x0007`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Subscribes a client to a stream, specifying from which offset to start consuming, how many credits to grant initially, and optional properties (e.g. for single-active-consumer).

## Protocol Structure
```
Subscribe => Key Version CorrelationId SubscriptionId Stream OffsetSpecification Credit Properties
  SubscriptionId => uint8
  OffsetSpecification => OffsetType Offset
  OffsetType => uint16 // 1 (first), 2 (last), 3 (next), 4 (offset), 5 (timestamp)
  Offset => uint64 (for offset) | int64 (for timestamp)
  Credit => uint16
  Properties => [Property]
  Property => Key Value (both string)

SubscribeResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/SubscribeRequestV1.php`
- [ ] Create `src/Response/SubscribeResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
