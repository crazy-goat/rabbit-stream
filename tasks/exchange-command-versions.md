# ExchangeCommandVersions

**Protocol Key:** `0x001b`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Client sends an array of supported commands (each with Key, MinVersion, MaxVersion); server responds with a ResponseCode and its own array of supported commands in the same structure. Used during connection negotiation to agree on protocol versions.

## Protocol Structure
```
CommandVersionsExchangeRequest => Key Version CorrelationId [Command]
  Command => Key MinVersion MaxVersion
  Key => uint16
  MinVersion => uint16
  MaxVersion => uint16
CommandVersionsExchangeResponse => Key Version CorrelationId ResponseCode [Command]
  Command => Key MinVersion MaxVersion
```

## Implementation Tasks
- [ ] Create `src/Request/ExchangeCommandVersionsRequestV1.php`
- [ ] Create `src/Response/ExchangeCommandVersionsResponseV1.php`
- [ ] Create a value object or DTO for `Command` (Key, MinVersion, MaxVersion)
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
