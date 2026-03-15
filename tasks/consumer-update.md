# ConsumerUpdate

**Protocol Key:** `0x001a`  
**Direction:** Both (Server → Client query; Client → Server response)  
**Expects Response:** Yes (client responds to server)

## Description
Used for single-active-consumer coordination. The server notifies the client whether its subscription is now active or inactive. The client responds with a ResponseCode and an OffsetSpecification indicating where to start (or resume) consuming.

## Protocol Structure
```
ConsumerUpdateQuery => Key Version CorrelationId SubscriptionId Active
  SubscriptionId => uint8
  Active => uint8 // 1 = active, 0 = inactive

ConsumerUpdateResponse => Key Version CorrelationId ResponseCode OffsetSpecification
  OffsetSpecification => OffsetType Offset
  OffsetType => uint16 // 0 (none), 1 (first), 2 (last), 3 (next), 4 (offset), 5 (timestamp)
  Offset => uint64 (for offset) | int64 (for timestamp)
```

## Implementation Tasks
- [ ] Create `src/Response/ConsumerUpdateResponseV1.php` (server-sent query received by client)
- [ ] Create `src/Request/ConsumerUpdateRequestV1.php` (client reply to server)
- [ ] Register query key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
