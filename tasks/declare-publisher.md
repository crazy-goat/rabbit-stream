# DeclarePublisher

**Protocol Key:** `0x0001`  
**Direction:** Client → Server (request/response)  
**Expects Response:** Yes

## Description
Registers a publisher on a given stream. The client provides a local PublisherId (used in subsequent Publish commands), an optional PublisherReference (for deduplication / sequence tracking), and the target Stream name. The server responds with a ResponseCode indicating success or failure.

## Protocol Structure
```
DeclarePublisherRequest => Key Version CorrelationId PublisherId [PublisherReference] Stream
DeclarePublisherResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/DeclarePublisherRequestV1.php`
- [ ] Create `src/Response/DeclarePublisherResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
