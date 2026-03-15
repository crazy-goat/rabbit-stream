# PublishError

**Protocol Key:** `0x0004`  
**Direction:** Server → Client (one-way)  
**Expects Response:** No

## Description
Sent by the server when one or more published messages could not be written. Contains the PublisherId and an array of PublishingError entries, each pairing a PublishingId with an error Code so the client can identify and handle failed messages individually.

## Protocol Structure
```
PublishError => Key Version PublisherId [PublishingError]
  PublishingError => PublishingId Code
  PublishingId => uint64
  Code => uint16
```

## Implementation Tasks
- [ ] Create `src/Response/PublishErrorResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
