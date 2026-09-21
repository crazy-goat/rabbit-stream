# Review — round 2 (convergence) — issue #463 (cross-object close idempotency test)

**Branch:** `feature/issue-463-close-idempotency-test`
**Commit under review:** `aa9e0df` — `docs: record review round 1 for #463`
**Base:** `main` (`git diff main...HEAD`)
**Reviewer:** review subagent (read-only w.r.t. source/tests/docs, except this file and `findings-review.md`)

## Scope

`git diff main...HEAD` contains exactly five files, all additive (no production
code changed):

- `tests/Client/ConnectionTest.php` (+123; two new tests + four request imports)
- `docs/proof_of_work/463-close-idempotency-test/code-decision-1.md` (new)
- `docs/proof_of_work/463-close-idempotency-test/findings-coder.md` (new)
- `docs/proof_of_work/463-close-idempotency-test/review-1.md` (new)
- `docs/proof_of_work/463-close-idempotency-test/findings-review.md` (new)

Working tree clean. This round verifies the two round-1 dispositions and
sweeps the diff for new issues.

## Verdict

**Round 1's fixes hold.** `review-1` (stale line numbers) is corrected, and
`review-2` (response-type stub) is a sound deliberate disposition. The new
tests remain correct and mutation-proven. The code/test deliverable is
merge-ready. The sweep found **one remaining documentation-only finding
(low)** in the round-1 review artifacts themselves — stale embedded line refs
for the `review-2` mock citation. It does not touch code or tests.

---

## 1. Verification of review-1 fix (stale line-number citations)

`code-decision-1.md:20` now reads
`ConnectionTest.php:919/963/991`. Resolved against HEAD:

| Cited | Actual at HEAD (`tests/Client/ConnectionTest.php`) | Match |
|---|---|---|
| 919 | `testClosingAProducerReleasesItsIdAndReference` | ✅ |
| 963 | `testClosingAProducerTwiceReleasesItsIdOnlyOnce` | ✅ |
| 991 | `testClosingAConsumerReleasesItsIdAndReference` | ✅ |

Confirmed the pre-fix `25b28d8` value was `915/959/987` and `aa9e0df` changed
only the two review docs; the test file at `25b28d8` is byte-identical to HEAD,
so the correction is against the right tree. **review-1: FIXED.**

## 2. Verification of review-2 disposition (mock response type)

`findings-review.md` (lines 83–90) marks this **deliberately not changed**.
The disposition is factually correct and I accept it:

- `Producer::declare()` discards the result — `src/Client/Producer.php:340`
  calls `$this->connection->readMessage();` with no assignment.
- `Consumer::sendSubscribe()` discards the result — `src/Client/Consumer.php:531`
  calls `$this->connection->request(...)` with no assignment.
- The assertions that actually carry the guarantee (request counters via the
  `sendMessage`/`request` capture, and `producersOf()`/`consumersOf()` map
  membership) are independent of the response body.

So the unconditional `CloseResponseV1` stub cannot cause a false pass today,
and the "key the stub on the request class if constructors ever validate the
response" note is the right way to record the future hazard. **review-2:
ACCEPTED as deliberate; no defect.**

## 3. New-issue sweep (`git diff main...HEAD`)

No new **code** issues. Checked in particular:

- **No production change.** The fix under test (`$closed` guards at
  `Consumer.php:794-797`, `Producer.php:540-543`; `onClose` unsets at
  `Connection.php:986-991`, `1108-1113`) is already on `main`; the diff only
  adds tests/docs.
- **Counter anchoring holds.** Each test asserts `assertSame(1, $declares)` /
  `assertSame(1, $subscribes)` before the sequence, so no counter can start
  wrong and pass trivially; `assertSame(1, $closes)` proves
  `Connection::close()` really ran (no vacuous pass).
- **No flakiness.** Deterministic mocks; no timers/sockets/ordering/shared
  state; the two tests use their own `StreamConnection` mock.
- **Imports used and ordered.** `DeclarePublisherRequestV1`,
  `DeletePublisherRequestV1`, `SubscribeRequestV1`,
  `UnsubscribeRequestV1` are all used and grouped alphabetically per PSR-12.
- **Test-suite membership.** Both tests are in the `unit` suite
  (`test:suite-coverage` passes).

### New finding

**R3 — Stale embedded line refs for the review-2 mock citation (low,
documentation-only).** Both `review-1.md:139` and `findings-review.md:51` cite
the mock stubs as `tests/Client/ConnectionTest.php:1045-1046,1099-1100`. At
HEAD those coordinates actually hold `registerMetadataUpdateHandler` and
`unregisterPublisher` — the stubs are at:

- producer `readMessage` + `willReturnCallback`: **1049–1050**
- consumer `request` + `willReturnCallback`: **1105–1106**

The citation is off by +4, the same offset class as the round-1 finding (the
four added request imports). Reader-indirection only; no code/test impact and
nothing in the deliverable is weakened. Suggested fix: update the two
citations to `1049-1050,1105-1106`. Non-blocking.

*(Not raised as a finding: `--testsuite unit` reports 8817 vs 8813 assertions
across runs — PHPUnit assertion counts are stable per test here and the tiny
delta is not something the proof docs depend on.)*

## 4. Gates (run locally at HEAD, PHP 8.5.10)

| Gate | Result |
|---|---|
| `./vendor/bin/phpunit --testsuite unit` | **OK (1244 tests, 8813 assertions)** |
| `composer lint` (PHPCS PSR-12 + Rector dry-run + PHPStan level 9 + kb-lint + docs links + suite coverage) | **OK** — PHPCS 281/281, Rector OK, PHPStan 275/275 "No errors", kb-lint 12 entries/0 warnings/0 stale, all relative links resolve, suite coverage OK |

## Findings index (see `findings-review.md` for dispositions)

| # | File:line | Severity | Summary |
|---|---|---|---|
| R3 | `docs/proof_of_work/463-close-idempotency-test/review-1.md:139`, `findings-review.md:51` | low | Review-2's mock citation points at `1045-1046,1099-1100`; actual stubs are at `1049-1050,1105-1106` (off by +4) |

**No open high or medium findings. One open low documentation finding (R3),
non-blocking. The code and test deliverable is clean.**
