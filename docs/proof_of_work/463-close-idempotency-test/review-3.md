# Review — round 3 (final convergence) — issue #463 (cross-object close idempotency test)

**Branch:** `feature/issue-463-close-idempotency-test`
**Commit under review:** `fca712d` — `docs: record review round 2 for #463`
**Base:** `main` (`git diff main...HEAD`)
**Reviewer:** review subagent (read-only w.r.t. source/tests/docs, except this
file and `findings-review.md`)

## Scope

`git diff main...HEAD` contains exactly six files, all additive (no production
code changed):

- `tests/Client/ConnectionTest.php` (+123; two new tests + four request imports)
- `docs/proof_of_work/463-close-idempotency-test/` — `code-decision-1.md`,
  `findings-coder.md`, `review-1.md`, `review-2.md`, `findings-review.md` (all new)

Working tree clean. This round verifies the round-2 R3 disposition against HEAD
and re-sweeps the diff for new issues.

## Verdict

**Converged. No open high, medium, low, or nit findings.** Round-1 findings are
fixed/deliberately accepted; the round-2 R3 (stale mock-stub line refs) is now
corrected and the corrected coordinates match HEAD. The new-issue sweep found
nothing. The code/test deliverable is clean and merge-ready.

## 1. R3 — mock-stub citation (round 2, low) — FIXED

`review-2.md` reported that `review-1.md:139` and `findings-review.md:51` cited
the mock stubs at `1045-1046,1099-1100` (off by +4). Both citations now read
`tests/Client/ConnectionTest.php:1049-1050,1105-1106`:

| Artifact | Line | Cited | Resolved at HEAD | Match |
|---|---|---|---|---|
| `review-1.md` | 139 | `1049-1050,1105-1106` | producer `readMessage` + `willReturnCallback` (1049–1050) | ✅ |
| `findings-review.md` | 51 | `1049-1050,1105-1106` | consumer `request` + `willReturnCallback` (1105–1106) | ✅ |

Independently resolved against HEAD (`tests/Client/ConnectionTest.php`): the
producer stub is at 1049–1050 (`$streamConnection->method('readMessage')` /
`->willReturnCallback(...)`) and the consumer stub at 1105–1106
(`$streamConnection->method('request')` / `->willReturnCallback(...)`). Both
match. The disposition note in `findings-review.md` (lines 79–83) correctly
records the correction and correctly notes `review-2.md` retains the original
numbers as the finding's own narrative. **R3: CLOSED.**

## 2. Round-1 findings — still closed

- **review-1** (stale code-decision line numbers): `code-decision-1.md:20` cites
  `ConnectionTest.php:919/963/991`, which resolve to
  `testClosingAProducerReleasesItsIdAndReference`,
  `testClosingAProducerTwiceReleasesItsIdOnlyOnce`,
  `testClosingAConsumerReleasesItsIdAndReference`. Verified still correct.
- **review-2** (single response type stub): accepted as deliberate; the
  constructor reads discard their result (`Producer.php:340`,
  `Consumer.php:531`) and the assertions are response-body-independent.

## 3. New-issue sweep (`git diff main...HEAD`)

Nothing new. Re-confirmed:

- **No production change** — the diff is tests + docs only; the `$closed`
  guards and `onClose` map unsets already exist on `main`.
- **Tests anchor their counters** (`assertSame(1, $declares)` /
  `assertSame(1, $subscribes)`) before the close sequence and prove
  `Connection::close()` actually ran (`assertSame(1, $closes)`), so no assertion
  can pass vacuously.
- **No flakiness / ordering / shared state**; each test builds its own mock.
- **Imports** all used and ordered per PSR-12.
- **Suite membership** — both tests are inside the `unit` suite.

## 4. Gates (run locally at HEAD, PHP 8.5.10)

| Gate | Result |
|---|---|
| `./vendor/bin/phpunit --testsuite unit` | **OK (1244 tests, 8813 assertions)** |
| `composer lint` (PHPCS PSR-12 + Rector dry-run + PHPStan level 9 + kb-lint + docs links + suite coverage) | **OK** — PHPCS 281/281, Rector OK, PHPStan 275/275 "No errors", kb-lint 12 entries / 0 warnings / 0 stale, all relative links resolve, suite coverage OK |

## Findings index

| # | File:line | Severity | Status |
|---|---|---|---|
| review-1 | `code-decision-1.md:20` | low | closed (fixed round 1) |
| review-2 | `tests/Client/ConnectionTest.php:1049-1050,1105-1106` | nit | closed (deliberate) |
| R3 | `review-1.md:139`, `findings-review.md:51` | low | closed (fixed round 2) |

**No open findings of any severity. Code and tests look good; merge-ready.**
