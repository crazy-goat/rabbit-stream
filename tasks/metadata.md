# Metadata

**Protocol Key:** `0x000f`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Queries metadata for one or more streams. The client sends an array of stream names and the server responds with broker information and per-stream metadata including leader and replica references.

## Protocol Structure
```
MetadataQuery => Key Version CorrelationId [Stream]
MetadataResponse => Key Version CorrelationId [Broker] [StreamMetadata]
  Broker => Reference Host Port
    Reference => uint16
    Host => string
    Port => uint32
  StreamMetadata => StreamName ResponseCode LeaderReference ReplicasReferences
    StreamName => string
    ResponseCode => uint16
    LeaderReference => uint16
    ReplicasReferences => [uint16]
```

## Implementation Tasks
- [ ] Create `src/Request/MetadataRequestV1.php`
- [ ] Create `src/Response/MetadataResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
