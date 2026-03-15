# Stream Stats

**Protocol Key:** `0x001c`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Retrieves statistics for a given stream. The client sends the stream name and the server responds with a response code and a map of statistic key-value pairs, where values are signed 64-bit integers.

## Protocol Structure
```
StreamStatsRequest => Key Version CorrelationId Stream
  Stream => string
StreamStatsResponse => Key Version CorrelationId ResponseCode Stats
  Stats => [Statistic]
  Statistic => Key Value
  Key => string
  Value => int64
```

## Implementation Tasks
- [ ] Create `src/Request/StreamStatsRequestV1.php`
- [ ] Create `src/Response/StreamStatsResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
