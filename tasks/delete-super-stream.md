# Delete Super Stream

**Protocol Key:** `0x001e`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Deletes an existing super stream (and all its partitions) from the RabbitMQ broker. The client sends the super stream name and the server responds with a response code indicating success or failure.

## Protocol Structure
```
DeleteSuperStream => Key Version CorrelationId Name
  Name => string
DeleteSuperStreamResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/DeleteSuperStreamRequestV1.php`
- [ ] Create `src/Response/DeleteSuperStreamResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
