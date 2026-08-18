# Review Findings — Issue #382 (select timeout tv_usec clamp)

Round 1. Format: `file:line | what is wrong | severity | what happened to it`.
Append-only for subsequent rounds.

## Findings on this diff

- src/StreamConnection.php:449-451 | (none — the clamp is correct) | — | The fix derives both `sec` and `usec` from `capped = min($remaining, 1)`, guaranteeing `tv_usec < 1_000_000` for every `$remaining > 0`. Proven by edge-case table + 100 000-value brute force (zero invalid post-fix; pre-fix invalid for all `remaining > 1.0`). No behaviour change for `remaining <= 1.0` (pre/post byte-identical). Not a finding — recorded as evidence the high-risk site was checked.

- src/StreamConnection.php:319-320 (sendFrame) | NOT changed; verified already correct | — | `sec = (int) $remaining` is the full integer part, so `usec = (int)(($remaining - $sec) * 1e6) < 1e6` always. Not touching it is right. (Automated check that could catch a regression here: a dedicated unit test on a `splitSelectTimeout()` helper — see residual risk.)

- src/StreamConnection.php:636-637 (readFrame) | NOT changed; verified already correct | — | Same pattern as sendFrame; additionally guarded by `$timeout > 0 ? ... : 0`. Called from readLoop with `timeout: 0.0` (non-blocking). Not touching it is right.

- tests/StreamConnectionTest.php:657-680 | Unit regression test is false-green on macOS for this specific EINVAL regression | low | Still present (round 1). BSD `select` clamps `tv_usec >= 1e6` instead of returning EINVAL, so pre-fix and post-fix produce the same elapsed time on macOS; the test only goes red on Linux (`ConnectionException`). Honestly documented in code-decision-1.md; mitigated by the E2E test on Linux CI. Smallest safe fix direction: extract `splitSelectTimeout(float): array{int,int}` and unit-test the `usec < 1_000_000` invariant directly (cross-platform), as the coder noted in findings-coder item 5 — out of scope for this issue but recommended as a follow-up.

- tests/E2E/ReadLoopTimeoutTest.php:72-87 | (none — real guard for Linux EINVAL) | — | Same 2.5 s / (2.3, 3.5) pattern against a real broker; this is the cross-platform CI guard that catches the bug on Linux. Teardown wiring correct (no streamName → delete skipped, connection closed). Not a finding — recorded as evidence.

## Out-of-scope / pre-existing items (from findings-coder.md; not introduced by this diff)

- tests/StreamConnectionTest.php:567 | `testDispatchMetadataUpdateWithoutCallbackDoesNotCrash` performs no assertions (PHPUnit "risky") | low | Still present; **pre-existing on `origin/main`** (verified `git show origin/main:tests/StreamConnectionTest.php`), out of scope for #382, not introduced by this branch. Automated check that flags it: PHPUnit risky-test detection (already firing).

- src/StreamConnection.php:337-348 (sendFrame) | Partial writes ignored (`socket_write` returning `0 < written < strlen` is accepted) | medium | Still present; pre-existing, tracked in #389, out of scope for #382, not introduced by this branch. No automated check currently catches it.

- src/StreamConnection.php:364-406 (readMessage) | Response correlation id never matched to the request id | medium | Still present; pre-existing, tracked in #387, out of scope for #382, not introduced by this branch. No automated check currently catches it.

- src/StreamConnection.php:632-642 (readFrame) | Single `socket_select` can block up to the full remaining timeout (30 s default) in one call; `stop()` cannot interrupt `readMessage` mid-select | low | Still present; by design for request/response semantics, out of scope for #382, not a defect introduced by this diff.

## Summary

- high: 0
- medium: 0 (in this diff; 2 pre-existing out-of-scope items noted above)
- low: 1 (in this diff: macOS false-green unit test, mitigated) + 2 pre-existing out-of-scope
- nit: 0
