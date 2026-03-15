# Create Stream

**Protocol Key:** `0x000d`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Creates a new stream on the RabbitMQ broker. The client sends a stream name along with optional key-value arguments (e.g., configuration parameters). The server responds with a response code indicating success or failure.

## Protocol Structure
```
Create => Key Version CorrelationId Stream Arguments
  Stream => string
  Arguments => [Argument]
  Argument => Key Value (both string)
CreateResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/CreateStreamRequestV1.php`
- [ ] Create `src/Response/CreateStreamResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
