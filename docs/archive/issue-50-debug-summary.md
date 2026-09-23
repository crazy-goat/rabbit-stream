# Issue #50 - OsirisChunk Parser E2E Test Failure

## Status
**RESOLVED** — All unit tests (188/188) and E2E tests (42/42) pass.

## Root Cause

The `MagicVersion` field in the OsirisChunk header is **not** a simple version byte with value `0x00`. It is a **combined byte** encoding both the Osiris magic number and the chunk format version:

```
MagicVersion byte = (MAGIC << 4) | VERSION
```

From the [Osiris source code](https://github.com/rabbitmq/osiris/blob/main/src/osiris.hrl):
```erlang
-define(MAGIC, 5).
-define(VERSION, 0).
```

So the correct MagicVersion byte is `0x50` = `(5 << 4) | 0`, not `0x00`.

The RabbitMQ Stream Protocol spec says `MagicVersion => int8` but doesn't document the encoding. The Go client simply discards this byte (`_ = readByte(r)`).

## Bugs Fixed

### 1. Wrong MagicVersion validation (PRIMARY)
**File:** `src/Client/OsirisChunkParser.php`

The parser expected `MagicVersion === 0x00` but the correct value is `0x50` (magic=5, version=0).

**Fix:** Decode the byte as `magic = (byte >> 4) & 0x0F`, `version = byte & 0x0F`, validate `magic === 5` and `version === 0`.

### 2. Buffer corruption from debug echo statements (SECONDARY)
**File:** `src/StreamConnection.php`

In `dispatchServerPush()`, the DELIVER case called `$frame->getRemainingBytes()` in debug echo statements before passing the frame to `DeliverResponseV1::fromStreamBuffer()`. Since `getRemainingBytes()` advances the buffer position to the end, the frame data was consumed before parsing.

**Fix:** Removed all debug echo statements from `dispatchServerPush()`, `readLoop()`, and `DeliverResponseV1::fromStreamBuffer()`.

### 3. Server-initiated CLOSE not handled
**File:** `src/StreamConnection.php`

The server can send a CLOSE frame (key=0x0016) at any time (e.g., heartbeat timeout). This was not in `SERVER_PUSH_KEYS` and not handled in `dispatchServerPush()`, causing `ResponseBuilder` to throw.

**Fix:** Added CLOSE to `SERVER_PUSH_KEYS`, added handler that reads the close request, sends a CLOSE response with OK, and closes the connection.

### 4. `close()` not idempotent
**File:** `src/StreamConnection.php`

Calling `close()` twice would throw `socket_close(): Argument #1 ($socket) has already been closed`.

**Fix:** Check `$this->connected` before closing, set `$this->socket = null` after close.

### 5. `readLoop()` and `readMessage()` not checking connection state
**File:** `src/StreamConnection.php`

After a server-initiated CLOSE, the loop would continue trying to use the closed socket.

**Fix:** Added `$this->connected` checks in the loop conditions and after dispatching server-push frames.

## Files Modified

- `src/Client/OsirisChunkParser.php` — Fixed MagicVersion validation
- `src/Response/DeliverResponseV1.php` — Removed debug echo statements
- `src/StreamConnection.php` — Fixed buffer corruption, added CLOSE handling, made close() idempotent
- `tests/Client/OsirisChunkParserTest.php` — Updated to use correct MagicVersion 0x50 (8 tests)
- `tests/E2E/OsirisChunkParserE2ETest.php` — Cleaned up debug output, added graceful cleanup

## Related Documentation

- RabbitMQ Stream Protocol: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
- Osiris chunk format: https://github.com/rabbitmq/osiris/blob/main/src/osiris.hrl
- Go client Deliver handler: https://github.com/rabbitmq/rabbitmq-stream-go-client/blob/main/pkg/stream/server_frame.go
