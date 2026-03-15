# StoreOffset

**Protocol Key:** `0x000a`  
**Direction:** Client → Server  
**Expects Response:** No

## Description
Stores a named offset (reference) for a stream, allowing a consumer to resume from a known position. The server sends no response.

## Protocol Structure
```
StoreOffset => Key Version Reference Stream Offset
  Reference => string
  Stream => string
  Offset => uint64
```

## Implementation Tasks
- [ ] Create `src/Request/StoreOffsetRequestV1.php`
- [ ] Write test/example
