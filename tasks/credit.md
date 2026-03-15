# Credit

**Protocol Key:** `0x0009`  
**Direction:** Client → Server  
**Expects Response:** Only on error

## Description
Grants additional credits to a subscription, allowing the server to send more message chunks. The server only responds if an error occurs (e.g. unknown subscription).

## Protocol Structure
```
Credit => Key Version SubscriptionId Credit
  SubscriptionId => uint8
  Credit => uint16

CreditResponse => Key Version ResponseCode SubscriptionId  // only on error
```

## Implementation Tasks
- [ ] Create `src/Request/CreditRequestV1.php`
- [ ] Create `src/Response/CreditResponseV1.php` (error-only response)
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
