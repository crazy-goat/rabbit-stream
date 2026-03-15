# Delete Stream

**Protocol Key:** `0x000e`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Deletes an existing stream from the RabbitMQ broker. The client sends the stream name and the server responds with a response code indicating success or failure.

## Protocol Structure
```
Delete => Key Version CorrelationId Stream
  Stream => string
DeleteResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/DeleteStreamRequestV1.php`
- [ ] Create `src/Response/DeleteStreamResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
