# Publish

**Protocol Key:** `0x0002`  
**Direction:** Client → Server (one-way)  
**Expects Response:** No

## Description
Sends one or more messages to a stream. The client references the publisher via PublisherId and provides an array of PublishedMessages, each carrying a client-assigned PublishingId (used for deduplication and confirm/error correlation) and the raw Message bytes. Protocol v2 adds an optional FilterValue per message for server-side filtering.

## Protocol Structure
```
Publish => Key Version PublisherId PublishedMessages
  PublishedMessages => [PublishedMessage]
  PublishedMessage => PublishingId Message
  PublishingId => uint64
  Message => bytes
```

### v2 addition
```
PublishedMessage => PublishingId FilterValue Message
  FilterValue => string
```

## Implementation Tasks
- [ ] Create `src/Request/PublishRequestV1.php`
- [ ] Create `src/Request/PublishRequestV2.php` (adds FilterValue per message)
- [ ] Write test/example
