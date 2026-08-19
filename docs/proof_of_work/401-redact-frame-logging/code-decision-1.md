# Code Decision #1 — Redacting SASL_AUTHENTICATE frames and gating bin2hex

## The problem

Two defects in `src/StreamConnection.php`:

1. **Credential/payload leak**: `sendFrame()` and `readFrame()` both call
   `bin2hex($frame)` and pass the result to `$this->logger->debug()`. For
   `SaslAuthenticateRequestV1`, the serialized body contains
   `\0username\0password` verbatim, so enabling debug logging writes the
   plaintext broker password (hex-encoded) and every message payload into the
   application log.

2. **Cost paid even when logging is off**: The `bin2hex()` argument is
   evaluated before the logger call. With `NullLogger` (the default) or any
   PSR-3 logger filtering out debug, `bin2hex()` still runs on every frame.

## Approach taken

### 1. `private readonly bool $debugLogging` computed once in the constructor

```php
$this->debugLogging = !$logger instanceof NullLogger;
```

**Why `!NullLogger` instead of an explicit setter?**

- Cheapest possible gate — one `readonly bool` property check, no method call
  overhead, no setter API to document or maintain.
- `NullLogger` is the documented default and the common case for production.
  If a real PSR-3 logger is injected, the user has already opted into logging
  and presumably wants debug output.
- An explicit setter (`setDebugLogging(bool)`) would add a mutable API surface
  to a class that is otherwise constructed once. The `readonly` flag keeps the
  class immutable w.r.t. this behavior, which is safer for multi-call usage
  patterns.
- If a user injects a real logger but filters debug at the logger level (e.g.
  Monolog with a minimum level of INFO), `bin2hex` will still run. This is
  acceptable: the user chose to inject a real logger, and the cost is only
  paid when a real logger is present. A more sophisticated approach would
  query the logger's `isHandling()` for debug level, but that is not part of
  the PSR-3 `LoggerInterface` contract and would couple this library to a
  specific logger implementation.

### 2. `debugFrame()` private helper — one method for both send and read paths

```php
private function debugFrame(string $prefix, string $frame, int $keyOffset): void
```

- Checks `$this->debugLogging` first — returns immediately if false. This is
  the defect-2 fix: `bin2hex()` never runs when logging is disabled.
- Extracts the 2-byte big-endian command key at `$keyOffset` within `$frame`.
- If the key is `KeyEnum::SASL_AUTHENTICATE->value` (0x0013), logs a redacted
  line: `"Socket -> <redacted: SASL_AUTHENTICATE, N bytes>"` — **no bin2hex
  call at all**, so no hex of credentials.
- Otherwise, logs `bin2hex($frame)` exactly as before, preserving the existing
  message format (`"Socket -> <hex>"` / `"Socket <-<hex>"`).

### 3. Key offset differences between send and read paths

**sendFrame** — `$frame` is the full wire frame including the 4-byte length
prefix (produced by `wrapFrame()` which prepends `addUInt32(strlen($content))`).
The key is at **offset 4**.

**readFrame** — `$frameData` is the content only; the 4-byte length prefix is
read and consumed separately by `readBytes(4)` + `unpack('N', ...)`, then
`readBytes($size)` returns just the payload. The key is at **offset 0**.

The `keyOffset` parameter handles this cleanly without duplicating logic.

## What was rejected

- **Redacting by frame content inspection (scanning for `\0` patterns)**:
  Fragile and would still require `bin2hex` or substring access to the frame
  body. Key-based detection is precise and cheap.
- **A custom `RedactingLogger` wrapper**: Would add a new class and indirection.
  The redaction is specific to this connection's frame logging, so keeping it
  in `StreamConnection` is more cohesive.
- **Gating with `$this->logger->isHandling('debug')`**: Not part of PSR-3
  `LoggerInterface`. Would couple to specific implementations.
- **Making `debugLogging` configurable via a constructor parameter**: Would add
  API surface. The `!NullLogger` heuristic is sufficient for the issue's
  requirements and keeps the default OFF.

## Edge cases handled

- **Frame too short to contain a key** (`strlen($frame) < $keyOffset + 2`):
  Falls through to normal hex logging. This should not happen in practice
  (every valid frame has at least key + version = 4 bytes of content), but
  the guard prevents an `unpack` error on malformed input.
- **`unpack('n', ...)` returning `false`**: Treated as non-SASL (falls through
  to normal hex logging). Again, should not happen with valid binary data.

## Assumptions

- PSR-3 log levels are always strings (per the PSR-3 specification). The
  `RecordingLogger` test helper uses `is_string()` to satisfy PHPStan level 9
  without a cast.
- The existing message format (`"Socket -> <hex>"` / `"Socket <-<hex>"`) is
  preserved for non-redacted frames. No existing log-scanning tests were
  found, but the format is kept identical as a safety measure.
