# Unsubscribe

**Protocol Key:** `0x000c`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Cancels an active subscription identified by its SubscriptionId. The server responds with a ResponseCode indicating success or failure.

## Protocol Structure
```
Unsubscribe => Key Version CorrelationId SubscriptionId
  SubscriptionId => uint8

UnsubscribeResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/UnsubscribeRequestV1.php`
- [ ] Create `src/Response/UnsubscribeResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
