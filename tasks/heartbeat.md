# Heartbeat

**Protocol Key:** `0x0017`  
**Direction:** Both  
**Expects Response:** No

## Description
Sent periodically in both directions to keep the connection alive. No payload beyond the standard Key and Version fields. No response is expected.

## Protocol Structure
```
Heartbeat => Key Version
  // No additional payload
```

## Implementation Tasks
- [ ] Create `src/Request/HeartbeatRequestV1.php` (for sending heartbeats)
- [ ] Handle incoming Heartbeat frames (server-initiated)
- [ ] Register key in `src/Enum/KeyEnum.php`
- [ ] Write test/example
