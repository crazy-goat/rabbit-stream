# PublishConfirm

**Protocol Key:** `0x0003`  
**Direction:** Server → Client (one-way)  
**Expects Response:** No

## Description
Sent by the server to confirm that one or more messages have been durably written to the stream. Contains the PublisherId and an array of PublishingIds that were successfully committed.

## Protocol Structure
```
PublishConfirm => Key Version PublisherId PublishingIds
  PublishingIds => [uint64]
```

## Implementation Tasks
- [ ] Create `src/Response/PublishConfirmResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
