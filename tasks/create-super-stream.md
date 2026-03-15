# Create Super Stream

**Protocol Key:** `0x001d`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Creates a super stream (a logical stream composed of multiple partitions) on the RabbitMQ broker. The client sends the super stream name, an array of partition names, an array of binding keys, and optional key-value arguments. The server responds with a response code.

## Protocol Structure
```
CreateSuperStream => Key Version CorrelationId Name [Partition] [BindingKey] Arguments
  Name => string
  Partition => string
  BindingKey => string
  Arguments => [Argument] (key-value strings)
CreateSuperStreamResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/CreateSuperStreamRequestV1.php`
- [ ] Create `src/Response/CreateSuperStreamResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
