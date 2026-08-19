# Findings — Review Round 1 — Issue #401

Review of branch `feature/issue-401-redact-frame-logging`, commit `ec34066`.
Format: `file:line` | what is wrong | severity | status | automated check that could catch it.

| file:line | what is wrong | severity | status (round 1) | automated check |
|-----------|---------------|----------|------------------|-----------------|
| tests/StreamConnectionTest.php:893-920 | Read-path redaction test `testSaslAuthenticateReadFrameIsRedactedWhenDebugLoggingEnabled` fabricates a frame with key **0x0013** on the read side. 0x0013 is the SASL_AUTHENTICATE *request* key; the server's response uses **0x8013** (`SASL_AUTHENTICATE_RESPONSE`), which `debugFrame()` does NOT redact. The comment at L901 calls the 0x0013 frame a "SASL_AUTHENTICATE **response** frame" — factually wrong about the protocol. The test name + comment imply read-path SASL responses are protected in production, which is not the case (and not needed, since the response carries no credentials). The helper logic being tested is correct and this is NOT a security gap, but the test documentation misleads future maintainers. | low | fixed (round 1) — renamed to `testDebugFrameRedactsFrameWithSaslAuthenticateRequestKeyOnReadPath` with a docblock stating it is a synthetic helper test (not a wire scenario; 0x0013 never appears on the read path); comment corrected (0x0013 = request key, 0x8013 = response key, not redacted because it carries no credentials). Added `testSaslAuthenticateResponseReadFrameIsLogedAsNormalHex` documenting that a real 0x8013 response frame is hex-logged normally. | none (semantic/accuracy defect in test docs; no tool flags misleading comments) |

Suggested fix direction: correct the comment (0x0013 = request key; response key = 0x8013, not redacted because it carries no credentials) and rename/relabel the test to make clear it is a synthetic exercise of `debugFrame()`'s key-match via the `readFrame()` entry point, not a real wire scenario. Optionally add a test asserting a real 0x8013 read frame is hex-logged normally to document the intended behavior.

---

# Findings — Review Round 2 — Issue #401

Review of branch `feature/issue-401-redact-frame-logging`, commit `45673e0`
(the round-1 fix commit). `src/` unchanged since round 1; only
`tests/StreamConnectionTest.php` and proof-of-work docs were modified.

## Reconfirmation of round-1 finding

| file:line (round 1) | what was wrong | severity | status (round 2) | automated check |
|-----------|---------------|----------|------------------|-----------------|
| tests/StreamConnectionTest.php:893-920 | Read-path redaction test fabricated a 0x0013 (REQUEST key) frame on the read side and mislabelled it a "SASL_AUTHENTICATE response frame". Misleading name + comment. | low | **fixed** (reconfirmed round 2) — test renamed to `testDebugFrameRedactsFrameWithSaslAuthenticateRequestKeyOnReadPath` (L907) with an accurate docblock (L893–906) stating it is a synthetic helper exercise (0x0013 never appears on the read path; 0x8013 is the response key, not redacted because it carries no credentials). Inline comment corrected (L915–917). New test `testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex` (L949) added and verified: builds a valid 0x8013 response frame (key+version+correlationId+responseCode OK), asserts normal hex logging with no redaction marker, passes. | none |

## New findings (round 2)

**None.** No new findings introduced by the round-1 fix. All automated checks
pass (cs, phpstan level 9, rector dry-run, 45 StreamConnection unit tests).
The round-1 finding is the only finding on this branch and it is now fixed.

## Informational note (not a finding)

The round-1 `findings-review.md` status cell and commit `45673e0`'s message
spell the new test as `testSaslAuthenticateResponseReadFrameIsLogedAsNormalHex`
("Loged"). The actual method name is `testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex`
("Logged"). Cosmetic doc-only typo; no code/test/security impact. Not classified
as a finding.
