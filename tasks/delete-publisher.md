# DeletePublisher

**Protocol Key:** `0x0006`  
**Direction:** Client → Server (request/response)  
**Expects Response:** Yes

## Description
Unregisters a previously declared publisher identified by PublisherId. After a successful response the PublisherId is released and must not be used for further Publish commands.

## Protocol Structure
```
DeletePublisherRequest => Key Version CorrelationId PublisherId
DeletePublisherResponse => Key Version CorrelationId ResponseCode
```

## Implementation Tasks
- [ ] Create `src/Request/DeletePublisherRequestV1.php`
- [ ] Create `src/Response/DeletePublisherResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
