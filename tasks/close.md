# Close

**Protocol Key:** `0x0016`  
**Direction:** Both  
**Expects Response:** Yes

## Description
Either the client or server can initiate a close. The initiating side sends a ClosingCode and ClosingReason; the other side responds with a ResponseCode.

## Protocol Structure
```
CloseRequest => Key Version CorrelationId ClosingCode ClosingReason
  ClosingCode => uint16
  ClosingReason => string
CloseResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/CloseRequestV1.php`
- [ ] Create `src/Response/CloseResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Handle server-initiated close (incoming CloseRequest from server)
- [ ] Write test/example
