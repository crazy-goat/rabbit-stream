# Review Round 1 — Issue #401 — Redact SASL_AUTHENTICATE frame logging

Branch: `feature/issue-401-redact-frame-logging`
Commit: `ec34066` — `fix(security): gate frame bin2hex logging behind debug flag, redact SASL_AUTHENTICATE frames (closes #401)`
Files touched: `src/StreamConnection.php`, `tests/StreamConnectionTest.php`, `tests/Util/RecordingLogger.php`

## Overall verdict

**FINDINGS — 1 low.** The change is sound and the security goal is met. One
low-severity finding: a fabricated read-path test has a factually incorrect
comment and a misleading name that overstate the read-path redaction coverage.

## Automated checks (all run locally, REQUIRED)

| Check | Command | Result |
|-------|---------|--------|
| PHPCS (PSR-12) | `composer cs` (`phpcs --standard=phpcs.xml.dist`) | **PASS** — 242 files, 0 errors (exit 0) |
| PHPStan level 9 | `composer phpstan` | **PASS** — 238 files, "No errors" (exit 0) |
| Rector dry-run | `composer rector` | **PASS** — "Rector is done!", no changes (exit 0) |
| Unit tests (StreamConnection) | `./vendor/bin/phpunit --testsuite unit --filter StreamConnection` | **PASS** — 44 tests, 117 assertions, 1 pre-existing risky (unrelated) |
| Unit tests (full suite) | `./vendor/bin/phpunit --testsuite unit` | **PASS** — 850 tests, 2812 assertions, 1 pre-existing risky (unrelated) |

The single risky test is `testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`
(`tests/StreamConnectionTest.php:569`), which predates this change (not in the
diff) and is unrelated to the logging work.

E2E suite intentionally NOT run (logging-only change, not wire-level).

## Wire-format correctness verification

**sendFrame keyOffset=4 — CORRECT.** `sendMessage()` serializes the request,
then `sendFrame($this->wrapFrame($content))`. `wrapFrame()` builds
`(new WriteBuffer())->addUInt32(strlen($content))->addRaw($content)->getContents()`
— a 4-byte big-endian length prefix followed by the content. The content
begins with the 2-byte key (from `getKeyVersion()` / V1Trait). So the key sits
at byte offset 4 within the frame passed to `debugFrame()`. ✓

**readFrame keyOffset=0 — CORRECT.** `readFrame()` does `readBytes(4)` for the
length prefix, unpacks it via `unpack('N', ...)`, then `readBytes($size)` into
`$frameData` (payload only, prefix already consumed). The first 2 bytes of
`$frameData` are the key. `debugFrame('Socket <-', $frameData, keyOffset: 0)`. ✓

**Key extraction — CORRECT.** `unpack('n', substr($frame, $keyOffset, 2))` uses
`'n'` = unsigned big-endian 16-bit, matching the wire format. `unpack()` returns
`array<int,int>|false`; the code guards with `$keyUnpacked !== false ?
$keyUnpacked[1] : null`, yielding `int|null` compared via `===` against
`KeyEnum::SASL_AUTHENTICATE->value` (int). PHPStan level 9 accepts this (passed).

**Frame-too-short branch — SAFE.** `if (strlen($frame) < $keyOffset + 2)` logs
`bin2hex($frame)` raw. A real SASL_AUTHENTICATE request always has at least
key(2)+version(2)+correlationId(4)+mechanism+auth, so a frame too short to
contain a key can never be a credential-bearing SASL frame. Logging the
truncated bytes raw cannot leak credentials. ✓

## Security verification

**Credential leak fully closed on the only leak vector (the request).** The
credential bytes (`\0username\0password`) live exclusively in
`SaslAuthenticateRequestV1::toStreamBuffer()` → `addBytes("\0".$user."\0".$pass)`,
i.e. the **request** frame (key 0x0013). That frame is sent via `sendFrame`,
which now calls `debugFrame(..., keyOffset: 4)`. When `debugLogging` is true and
the key === 0x0013, the helper logs only `<redacted: SASL_AUTHENTICATE, N bytes>`
— `bin2hex()` is **never** invoked for that frame, so neither raw nor hex
credential bytes reach the logger. Test
`testSaslAuthenticateFrameIsRedactedWhenDebugLoggingEnabled` asserts exactly
this (no raw username/password, no hex of either). ✓

**Response key (0x8013) is NOT redacted on read — and that is fine.**
`SaslAuthenticateResponseV1` extends `SimpleCorrelatedResponseV1`, which
deserializes only key+version+correlationId+responseCode. The server's
SASL_AUTHENTICATE response carries no client credentials (for PLAIN there is no
challenge blob the library even parses). The credential leak was solely in the
request; redacting only key 0x0013 is sufficient for the security goal.

**No other path dumps raw frame bytes.** All `logger->*` calls in
`StreamConnection.php`:
- L486 `warning(...)` — logs only `sprintf('0x%04x', $key)`, no frame body. Safe.
- L570 `debug(sprintf('Server-initiated close: code=%d, reason=%s', ...))` —
  logs the server-supplied close reason string, not raw frame bytes, and not
  credentials. Not gated by `$debugLogging` (negligible `sprintf` cost); noted
  by the coder as out-of-scope. Not a blocker.
- L703/L712/L720 — all inside the gated `debugFrame()`. ✓

**Redaction marker is credential-free.** It contains only the prefix, the
literal `redacted: SASL_AUTHENTICATE`, and `strlen($frame)`. The byte count is
the total frame length (includes length prefix + mechanism + overhead), so it
does not directly expose the password length. Acceptable information leakage
for the operational visibility gained.

**`$debugLogging` heuristic.** `!$logger instanceof NullLogger` computed once in
the constructor. If a real logger filters debug at the logger level, `bin2hex`
still runs — but redaction still applies, so this is a partial-perf tradeoff,
never a security regression. Acceptable and documented in the coder's decision.

## Type / style verification

- `private readonly bool $debugLogging` assigned in the constructor body is
  valid PHP 8.1 (a readonly property may be initialized once in the ctor body).
- Named argument `keyOffset: 4` is PSR-12/PHPCS-clean (PHPCS passed).
- `RecordingLogger` PSR-3 `log()` uses `is_string($level) ? $level : ''` to
  satisfy PHPStan level 9 without a `mixed`-cast. PHPStan passed.
- `RecordingLogger` placed in `tests/Util/`, an established directory
  (`tests/Util/TypeCastTest.php` already exists on `origin/main`). Correct.
- One class per file (PHPCS `PSR1.Classes.ClassDeclaration` satisfied).

## Test coverage verification

- Send path, SASL redacted: `testSaslAuthenticateFrameIsRedactedWhenDebugLoggingEnabled` ✓
- Send path, non-SASL hex-logged: `testNonSaslFrameProducesNormalHexDebugLineWhenDebugLoggingEnabled` (TuneRequestV1, key 0x0017) ✓
- Read path, hex-logged for non-SASL: `testNonSaslReadFrameProducesNormalHexDebugLineWhenDebugLoggingEnabled` (key 0x0014) ✓
- NullLogger gate, no debug output on send: `testNullLoggerProducesNoDebugOutputOnSendFrame` ✓
  (Cannot directly assert `bin2hex` was skipped, but the `$debugLogging === false`
  early-return makes it impossible — acceptable; the existing inline comment notes this.)
- Helper methods `createSocketPair()`, `injectSocket()`, `buildFrame()` all exist
  (L686/L693/L702) and are used correctly.

## Explicit verdict on the read-path redaction test (0x0013 fabricated on read)

`testSaslAuthenticateReadFrameIsRedactedWhenDebugLoggingEnabled`
(`tests/StreamConnectionTest.php:893`) writes a frame with key **0x0013** from
the server side and asserts it is redacted on `readFrame()`.

**This is misleading.** On the real wire the server never sends 0x0013 — that is
the *request* key. The server's SASL_AUTHENTICATE response uses **0x8013**
(`SASL_AUTHENTICATE_RESPONSE`), which `debugFrame()` does **not** redact. The test
comment (L901) compounds the error by calling the 0x0013 frame a
"SASL_AUTHENTICATE **response** frame" — factually wrong about the protocol.

The test does exercise `debugFrame()`'s key-match logic through the `readFrame()`
entry point (valid helper-level coverage), and the underlying logic is correct.
It is **not a security gap** (the 0x8013 response carries no credentials). But
the name + comment create a false impression that read-path SASL responses are
protected in production, and they misstate the protocol. Classified **LOW**.

Suggested fix (smallest safe direction): correct the comment to state 0x0013 is
the *request* key (the response key is 0x8013, which is not redacted because it
carries no credentials), and rename/relabel the test to make clear it is a
synthetic exercise of the helper's key-match on the read entry point — not a real
wire scenario. Optionally add a second test asserting a real 0x8013 read frame is
hex-logged normally (documents the intended behavior explicitly).

Which automated check could catch it: **none** — this is a semantic/accuracy
defect in test documentation; no tool flags misleading comments.

## Remaining risk areas checked clean

- Wire offsets (send=4, read=0): verified against `wrapFrame`/`readFrame`. Clean.
- Big-endian uint16 decode + false guard: verified. Clean.
- Truncated-frame raw-log safety: verified (no credential exposure). Clean.
- Other logger call-sites dumping frame bytes: none. Clean.
- Redaction marker payload leakage: acceptable (length only). Clean.
- PHP 8.1 readonly-in-ctor-body: valid. Clean.
- PSR-12 / PHPStan-9 / Rector: all clean.
- Test helper placement and existence: clean.

## Out of scope (noted, not blocking)

- `handleServerClose` (L570) debug log is ungated by `$debugLogging` but logs no
  frame bytes / no credentials — coder noted as low priority.
- Future SASL mechanisms putting credentials in a different frame: out of scope.
- CHANGELOG `[Unreleased]` entry: step 8, not the coder's job; coder did not
  add it. README status table correctly unchanged (not a new protocol command).
