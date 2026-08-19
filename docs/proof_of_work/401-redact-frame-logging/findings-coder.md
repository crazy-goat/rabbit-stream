# Findings — Coder — Issue #401

## Obstacles / surprises

### 1. PHPCS: Multiple classes per file

The initial implementation placed a `RecordingLogger` class at the bottom of
`tests/StreamConnectionTest.php`. PHPCS (PSR1.Classes.ClassDeclaration.MultipleClasses)
rejects this. Fixed by extracting `RecordingLogger` into its own file
`tests/Util/RecordingLogger.php`, following the existing `tests/Util/` pattern.

### 2. PHPStan level 9: `mixed` type from PSR-3 `log()` signature

The PSR-3 `LoggerInterface::log()` method declares `$level` without a type
(implicit `mixed`). PHPStan level 9 flags `(string) $level` as "Cannot cast
mixed to string." Fixed by using `is_string($level) ? $level : ''` instead of
a cast. This is safe because PSR-3 levels are always strings per the spec.

### 3. Testing readFrame in isolation

`readFrame()` was testable using the existing socket-pair pattern
(`createSocketPair()` + `injectSocket()`) already used throughout
`StreamConnectionTest.php`. No new infrastructure was needed. Both the send
and read paths are covered by dedicated tests.

## Bugs / weak spots noticed in passing (out of scope)

### Finding 1 — `handleServerClose` logs `closingReason` at debug (potential info leak)

**File**: `src/StreamConnection.php:570`
**Issue**: `handleServerClose()` logs the server-supplied `closingReason`
string via `$this->logger->debug(sprintf('Server-initiated close: code=%d,
reason=%s', $closingCode, $closingReason ?? ''))`. While not a credential
leak, the close reason could contain sensitive operational information. This
call is also ungated by `$this->debugLogging`, so it runs even with
NullLogger (though the cost is negligible — just a `sprintf`). More
importantly, unlike the frame-dumping calls, this does not benefit from the
`$debugLogging` gate.
**Suggested fix**: Gate behind `if ($this->debugLogging)` for consistency, or
leave as-is if close reasons are considered safe to always log. Low priority.

### Finding 2 — `readLoop` warning log is ungated but low-cost

**File**: `src/StreamConnection.php:486`
**Issue**: `$this->logger->warning(...)` in `readLoop()` for unexpected
non-server-push frames is not gated by `$this->debugLogging`. This is
acceptable because `warning` is a different level and should always be
emitted. No fix needed — noted for completeness.

### Finding 3 — `sendMessage` correlation ID is set via mutable state on request objects

**File**: `src/StreamConnection.php:282-285`
**Issue**: `sendMessage()` calls `$request->withCorrelationId($this->correlationId)`
which mutates the request object in place (via `CorrelationTrait`). If the
same request object is reused for retransmission, the correlation ID from the
first send persists and is overwritten. This is a pre-existing design choice,
not a regression.
**Suggested fix**: Document that request objects are single-use, or have
`sendMessage` create a clone. Out of scope for this issue.

### Finding 4 — `SOCKET_ETIMEDOUT` constant may not exist on all platforms

**File**: `src/StreamConnection.php:736`
**Issue**: `SOCKET_ETIMEDOUT` is used as a constant. On PHP 8.0+ with the
sockets extension, this constant exists (value 60 on Linux/macOS). However,
it is not guarded with `defined()` check. If the constant were unavailable
(theoretically on some minimal builds), this would throw a fatal error.
**Suggested fix**: Use `defined('SOCKET_ETIMEDOUT')` guard or the numeric
value with a comment. Very low risk — the sockets extension always defines it.
