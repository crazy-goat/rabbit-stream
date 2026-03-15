# QueryPublisherSequence

**Protocol Key:** `0x0005`  
**Direction:** Client → Server (request/response)  
**Expects Response:** Yes

## Description
Queries the last PublishingId recorded by the broker for a given PublisherReference on a given Stream. Used by publishers at startup to resume deduplication from where they left off. The server responds with a ResponseCode and the last known Sequence value (0 if none exists).

## Protocol Structure
```
QueryPublisherRequest => Key Version CorrelationId PublisherReference Stream
QueryPublisherResponse => Key Version CorrelationId ResponseCode Sequence
  Sequence => uint64
```

## Implementation Tasks
- [ ] Create `src/Request/QueryPublisherSequenceRequestV1.php`
- [ ] Create `src/Response/QueryPublisherSequenceResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
