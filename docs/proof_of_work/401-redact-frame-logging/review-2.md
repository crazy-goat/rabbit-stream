# Review Round 2 — Issue #401 — Redact SASL_AUTHENTICATE frame logging

**CLEAN round — no open findings.**

Branch: `feature/issue-401-redact-frame-logging`
Commit under review: `45673e0` — `fix(tests): clarify synthetic read-path redaction test, document real 0x8013 response hex-logging`
Files touched by the round-1 fix: `tests/StreamConnectionTest.php`, `docs/proof_of_work/401-redact-frame-logging/findings-review.md`, `docs/proof_of_work/401-redact-frame-logging/review-1.md`
`src/` untouched by this commit (verified: `git show 45673e0 --name-only` lists no `src/` files).

## 1. Status of the round-1 finding

**FIXED.** The single round-1 low-severity finding is resolved.

The offending test `testSaslAuthenticateReadFrameIsRedactedWhenDebugLoggingEnabled`
(`tests/StreamConnectionTest.php:893`) was:

1. **Renamed** to `testDebugFrameRedactsFrameWithSaslAuthenticateRequestKeyOnReadPath`
   (L907). The new name accurately reflects that it exercises `debugFrame()`'s
   key-match branch via the `readFrame()` entry point using the **request** key.
2. **Given a docblock** (L893–906) stating explicitly:
   - it is a *synthetic* test, NOT a real wire scenario;
   - a server never sends 0x0013 on the read path;
   - the SASL_AUTHENTICATE *response* uses 0x8013 (`KeyEnum::SASL_AUTHENTICATE_RESPONSE`);
   - `debugFrame()` does NOT redact 0x8013 (and does not need to — the response
     carries no client credentials; the leak is solely in the request);
   - cross-references `testSaslAuthenticateFrameIsRedactedWhenDebugLoggingEnabled`
     (the real send-path redaction test at L839 — confirmed to exist).
3. **Inline comment corrected** (L915–917): now reads "Fabricated frame with the
   SASL_AUTHENTICATE REQUEST key (0x0013) … Written from the server side purely
   to drive debugFrame() through readFrame(); never occurs on the wire." — no
   longer calls it a "response frame".

Protocol accuracy verified against `src/Enum/KeyEnum.php`:
`SASL_AUTHENTICATE = 0x0013` (L27), `SASL_AUTHENTICATE_RESPONSE = 0x8013` (L63).
The docblock's claims are correct.

A **new test** `testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex` (L949)
was added to document the intended behaviour for real response frames — see
section 2 below.

## 2. New test verification — `testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex`

### Frame construction (L963–964)

```php
$payload = pack('nn', 0x8013, 1) . pack('N', 1) . pack('n', 0x0001);
```

- `pack('nn', 0x8013, 1)` → key=0x8013 (uint16 BE) + version=1 (uint16 BE) ✓
- `pack('N', 1)` → correlationId=1 (uint32 BE) ✓
- `pack('n', 0x0001)` → responseCode=0x0001 = `ResponseCodeEnum::OK` (uint16 BE) ✓

This exactly matches the wire format parsed by
`SimpleCorrelatedResponseV1::fromStreamBuffer()` (key+version+correlationId+
responseCode) and `SaslAuthenticateResponseV1` extends that base class with
`getKey()` returning `KeyEnum::SASL_AUTHENTICATE_RESPONSE->value` (0x8013). The
frame is a **valid real-world response frame** with no credential body. ✓

The length-prefixed write `pack('N', strlen($payload)) . $payload` is the
standard frame envelope (4-byte BE length + payload), matching `readFrame()`'s
`readBytes(4)` → `unpack('N')` → `readBytes($size)` sequence. ✓

### Assertions (L968–972)

| Assertion | What it checks | Correct? |
|-----------|----------------|----------|
| `assertCount(1, $debugMessages)` | `readFrame()` logs exactly one debug line via `debugFrame()` | ✓ |
| `assertStringStartsWith('Socket <-', …)` | read-path prefix is `'Socket <-'` (L676: `debugFrame('Socket <-', …)`) | ✓ |
| `assertStringNotContainsString('redacted', …)` | key 0x8013 ≠ 0x0013, so no redaction marker | ✓ |
| `assertMatchesRegularExpression('/^Socket <-[0-9a-f]+$/', …)` | message is `'Socket <-' . bin2hex($frameData)` — 10 payload bytes → 20 hex chars, no space after `<-` | ✓ |

The regex `/^Socket <-[0-9a-f]+$/` is identical to the one used by the
pre-existing `testNonSaslReadFrameProducesNormalHexDebugLineWhenDebugLoggingEnabled`
(L984) which passed in round 1 — confirms the format string has no trailing
space on the read path (`'Socket <-'` vs the send path's `'Socket -> '` with
space, L304). Consistent and correct. ✓

### Does it pass?

Yes. Confirmed by isolated run:

```
./vendor/bin/phpunit --testsuite unit \
  --filter "testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex|testDebugFrameRedactsFrameWithSaslAuthenticateRequestKeyOnReadPath"
→ OK (2 tests, 11 assertions)
```

The renamed test contributes 6 assertions; the new test contributes 4
assertions + 1 count = 5 → wait, actually 4 assertions total (assertCount,
assertStringStartsWith, assertStringNotContainsString, assertMatchesRegularExpression).
The full StreamConnection suite grew from 44 tests/117 assertions (round 1) to
45 tests/121 assertions (+1 test, +4 assertions) — exactly matching the new
test's 4 assertions. ✓

## 3. Dangling references to the old test name

`grep -rn "testSaslAuthenticateReadFrameIsRedactedWhenDebugLoggingEnabled"` finds
the old name only in:
- `docs/proof_of_work/401-redact-frame-logging/review-1.md:119` — historical
  round-1 review document. Expected: it records what was found in round 1.
- `docs/proof_of_work/401-redact-frame-logging/findings-review.md:8` — the
  finding row itself. Expected: it describes the defect by its original name.

These are **historical records**, not dangling references — they legitimately
cite the name as it existed at the time of the finding. No code, no other test,
no data provider, and no active documentation references the old name. ✓

## 4. Docblock accuracy

The docblock on the renamed test (L893–906) is accurate:
- "a server never sends 0x0013 on the read path" — correct (0x0013 is the
  client→server request key).
- "the SASL_AUTHENTICATE *response* uses 0x8013
  (KeyEnum::SASL_AUTHENTICATE_RESPONSE)" — correct per KeyEnum L63.
- "debugFrame() does NOT redact (and does not need to: the response carries no
  client credentials)" — correct; `debugFrame()` only redacts when
  `$key === KeyEnum::SASL_AUTHENTICATE->value` (0x0013), and the response carries
  no credentials (SimpleCorrelatedResponseV1 parses only key+version+correlationId+
  responseCode).
- Cross-reference to `testSaslAuthenticateFrameIsRedactedWhenDebugLoggingEnabled`
  — verified present at L839. ✓

The docblock on the new test (L942–947) is accurate: it states the response
carries no credentials and that `debugFrame()` must log it as normal hex, guarding
against over-redaction on the read path. ✓

## 5. Lint / type / style issues from the added test

- PHPCS (PSR-12): **PASS** — 242 files, 0 errors.
- PHPStan level 9: **PASS** — 238 files, no errors.
- Rector dry-run: **PASS** — no changes suggested.
- No new `mixed` types, no unused imports, no style violations introduced.
- The `pack()` calls produce `string` (non-nullable); `socket_write()` return
  value is intentionally ignored (consistent with all other tests in this file).

## 6. Automated checks (all run locally, REQUIRED)

| Check | Command | Result |
|-------|---------|--------|
| PHPCS (PSR-12) | `composer cs` | **PASS** — 242 files, 0 errors (exit 0) |
| PHPStan level 9 | `composer phpstan` | **PASS** — 238 files, "No errors" (exit 0) |
| Rector dry-run | `composer rector` | **PASS** — "Rector is done!", no changes (exit 0) |
| Unit tests (StreamConnection) | `./vendor/bin/phpunit --testsuite unit --filter StreamConnection` | **PASS** — 45 tests, 121 assertions, 1 pre-existing risky (unrelated) |

The single risky test is `testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`
(`tests/StreamConnectionTest.php:569`), which predates this change (not in the
diff) and is unrelated to the logging work. Neither the renamed test nor the new
test is risky — both have assertions and pass cleanly.

E2E suite intentionally NOT run (test-documentation-only change; no wire-level
or source changes in this commit).

## 7. Informational note (not a finding, not blocking)

The round-1 `findings-review.md` status cell and the commit message of `45673e0`
both spell the new test name as `testSaslAuthenticateResponseReadFrameIsLogedAsNormalHex`
("Loged" — missing a second 'g'). The **actual** method name in the code is
`testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex` ("Logged" — correct).
This is a cosmetic typo in a proof-of-work markdown file and a commit message;
it does not affect any code, test execution, or security posture. A `grep` for
the misspelled name would not match the code, but this is a documentation nit,
not a correctness or safety issue. Not classified as a finding.

## 8. Remaining risk areas checked

| Area | Status |
|------|--------|
| Round-1 finding resolved (rename + docblock + comment) | ✓ Fixed |
| New 0x8013 test frame wire-correct (key/version/correlationId/responseCode) | ✓ Verified against SimpleCorrelatedResponseV1 + KeyEnum + ResponseCodeEnum |
| New test assertions correct (prefix, no-redaction, hex regex) | ✓ Verified against debugFrame() format strings |
| New test passes | ✓ Confirmed (isolated + suite run) |
| No dangling references to old test name in code/tests | ✓ Clean (only historical docs) |
| Docblocks protocol-accurate | ✓ Verified |
| No new lint/type/style issues | ✓ cs/phpstan/rector clean |
| No src/ changes in fix commit | ✓ Verified via git show --name-only |
| No staged files | ✓ `git diff --cached` empty |
