# Findings — Review (issue #463)

Round 1 (first review round; `findings-review.md` did not exist before this
review). One entry per finding; rulings on the coder's findings first, then the
reviewer's own.

## Rulings on coder findings (findings-coder.md)

### coder-1 — `ConsumerInterface` / `ProducerInterface` expose `close()` but not `isClosed()`
- **Verdict:** real, intentional, **not a defect of this PR**. The concrete
  classes expose `isClosed()`; the interfaces deliberately do not, which is why
  the new tests narrow with `assertInstanceOf(Producer::class, …)` /
  `(Consumer::class, …)`. Adding it to the interfaces would be a BC break for
  external implementors, consistent with the existing decision for #522's
  `getLostConfirmCount()`. **No action.**
- Severity: none (informational).

### coder-2 — `Connection::close()` swallows every `\Throwable` from a handle close and only logs a warning
- **Verdict:** accurate observation, **not a defect**, and it does not weaken the
  new tests. Now that handles are removed from the map on user close, the
  warning path
  (`testCloseLogsWarningWhenProducerCloseThrows` /
  `testCloseLogsWarningWhenConsumerCloseThrows`) is exercised only via injected
  throwing mocks. That is the correct way to test the catch/log behaviour; the
  real bug it used to mask is gone. **No action.**
- Severity: none (informational).

The coder reports **no bugs in the close paths**; the reviewer agrees after
inspecting `Consumer::close()`, `Producer::close()`, `Connection::close()`, and
both `onClose` callbacks.

## Review's own findings (round 1)

### review-1 — Stale line-number citations in the code decision doc
- `docs/proof_of_work/463-close-idempotency-test/code-decision-1.md:20`
- **What happened:** the doc cites the pre-existing id-reclamation tests as
  `ConnectionTest.php:915/959/987`. Those were correct against `main`, but this
  same diff adds four `use` statements, shifting every later line by +4. At HEAD
  the tests are at `tests/Client/ConnectionTest.php:919/963/991` (verified with
  `git show main:...` and `grep -n` on HEAD).
- **Why it matters:** reader-indirection only; no code or test impact. But the
  review contract is to catch exactly this kind of divergence between a proof
  document and the tree it ships with.
- **Severity:** low.
- **Check that could have caught it:** a docs line-reference check that resolves
  cited `File.php:NNN` against HEAD (the repo already lints docs *links* via
  `composer lint`, but not embedded line numbers), or simply re-`grep`ping the
  cited numbers after adding imports.

### review-2 — Mock returns one response type for all read/request calls
- `tests/Client/ConnectionTest.php:1049-1050,1105-1106`
- **What happened:** in both tests, `readMessage()` and (for the consumer)
  `request()` are stubbed to return `new CloseResponseV1()` unconditionally.
  The producer's constructor read (DeclarePublisher response) and the consumer's
  constructor read (Subscribe response, via `request()`) therefore also receive a
  `CloseResponseV1`. This works only because `Producer::declare()` discards the
  `readMessage()` result and `Consumer::sendSubscribe()` discards the
  `request()` result.
- **Why it matters:** it does **not** weaken the idempotency assertions (the
  counter capture is independent of the response body), so this is not a defect.
  It is a maintainability nit: adding response-type validation to those
  constructors later would silently break these mocks.
- **Severity:** nit.
- **Check that could have caught it:** returning the type-correct response per
  call (a `willReturnCallback` keyed on the request class, as the file already
  does elsewhere for `MetadataResponseV1`), or an `expects($this->once())`
  response-shape assertion in the constructors.

## Status summary

- Round 1: **clean — no code/test findings requiring fixes.** The two new tests
  assert the cross-object guarantee and are independently mutation-verified
  (guard and map-cleanup removal each fail a distinct assertion).
- Open findings: **review-1 (low, docs line numbers)** and **review-2 (nit,
  mock response type)** — both optional follow-ups, neither a merge blocker.

## Round 2 fix disposition (coder)

### R3 — mock-stub citation off by +4
- **Fixed.** Corrected the citation from `1045-1046,1099-1100` to
  `1049-1050,1105-1106` in `review-1.md:139` and `findings-review.md:51`.
  Documentation-only; no code change. The finding remains on record in
  `review-2.md` (which cites the original numbers as part of the finding).

## Round 1 fix dispositions (coder, post-review)

### review-1 — stale line-number citations
- **Fixed.** `code-decision-1.md:20` now cites `ConnectionTest.php:919/963/991`,
  the actual HEAD locations after the four added `use` imports. No code change.

### review-2 — mock returns one response type for all read/request calls
- **Deliberately not changed.** The unconditional `CloseResponseV1` stub is
  intentional and mirrors the pre-existing reclaim tests in the same file; the
  assertions under test (request counters, map membership) are independent of the
  response body, and the constructor reads discard their result by design. A
  future response-type validation in those constructors would be the moment to
  key the stub on the request class — tracked here rather than pre-emptively
  complicating the mock. Not a defect; no gate weakened.
- Gates: `ConnectionTest.php` OK (50 tests), `--testsuite unit` OK (1244 tests,
  8817 assertions), `composer lint` OK (PHPCS, Rector, PHPStan level 9, kb-lint,
  docs links, suite coverage).

## Round 2 (convergence)

Round-2 review kept the two round-1 findings closed and swept the diff for new
issues. See `review-2.md` for the full record.

### review-1 — stale line-number citations (round 1)
- **Verified fixed.** `code-decision-1.md:20` cites `ConnectionTest.php:919/963/991`,
  which resolve at HEAD to `testClosingAProducerReleasesItsIdAndReference`,
  `testClosingAProducerTwiceReleasesItsIdOnlyOnce`, and
  `testClosingAConsumerReleasesItsIdAndReference`. **Closed.**

### review-2 — mock returns one response type for all read/request calls (round 1)
- **Disposition accepted.** `Producer::declare()` discards the `readMessage()`
  result (`src/Client/Producer.php:340`) and `Consumer::sendSubscribe()` discards
  the `request()` result (`src/Client/Consumer.php:531`), so the unconditional
  `CloseResponseV1` stub cannot cause a false pass, and the assertions that
  matter are independent of the response body. **Closed as deliberate.**

### R3 — stale embedded line refs for the review-2 mock citation
- `review-1.md:139` and `findings-review.md:51` (this file) cite the stubs as
  `tests/Client/ConnectionTest.php:1045-1046,1099-1100`. At HEAD those lines hold
  `registerMetadataUpdateHandler` / `unregisterPublisher`; the stubs are at
  `1049-1050` (producer `readMessage`) and `1105-1106` (consumer `request`) —
  off by +4, the same offset introduced by the four added request imports.
- **Severity:** low (documentation-only, reader indirection; no code/test impact).
- **Status:** open, non-blocking. Suggested fix: update the two citations to
  `1049-1050,1105-1106`.

## Round 2 status summary

- Round 1 findings: **both closed** (review-1 fixed, review-2 accepted as
  deliberate).
- New findings: **R3 (low, docs)** — the only open item, non-blocking, in the
  review artifacts only.
- No open high or medium findings. The code/test deliverable is clean and
  merge-ready.
- Gates at HEAD: `--testsuite unit` OK (1244 tests, 8813 assertions);
  `composer lint` OK (PHPCS 281/281, Rector, PHPStan level 9 275/275, kb-lint,
  docs links, suite coverage).
