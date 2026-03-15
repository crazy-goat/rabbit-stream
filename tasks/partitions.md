# Partitions

**Protocol Key:** `0x0019`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Client sends a SuperStream name; server responds with a ResponseCode and an array of Stream names representing the partitions of that SuperStream.

## Protocol Structure
```
PartitionsQuery => Key Version CorrelationId SuperStream
  SuperStream => string
PartitionsResponse => Key Version CorrelationId ResponseCode [Stream]
  Stream => string
```

## Implementation Tasks
- [ ] Create `src/Request/PartitionsRequestV1.php`
- [ ] Create `src/Response/PartitionsResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
