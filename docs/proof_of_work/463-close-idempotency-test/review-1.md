# Review — round 1 — issue #463 (cross-object close idempotency test)

**Branch:** `feature/issue-463-close-idempotency-test`
**Commit under review:** `25b28d8` — `test(connection): add cross-object close idempotency regression (closes #463)`
**Base:** `main` (`git diff main...HEAD`)
**Reviewer:** review subagent (read-only w.r.t. source/tests/docs, except this file and `findings-review.md`)

## Scope

`git diff main...HEAD` contains exactly three files, all additive:

- `tests/Client/ConnectionTest.php` (+123; two new tests + four request imports)
- `docs/proof_of_work/463-close-idempotency-test/code-decision-1.md` (new)
- `docs/proof_of_work/463-close-idempotency-test/findings-coder.md` (new)

Working tree clean, branch based on `main`. No production code changed — the
`$closed` guards and the `onClose` map cleanup already exist in `main` (PR
#504 / #388). This is a test-only follow-up, as the issue requested.

## Verdict

**The two new tests are correct, meaningful, and non-trivial.** They assert the
exact cross-object guarantee, and both failure modes named by #463 are caught —
independently re-verified here by mutation testing in a throwaway copy. No high,
medium, or low code findings. One **low** documentation finding (stale
line-number citations). Nothing blocks merge.

---

## 1. Does the test assert the cross-object guarantee?

The issue names two independent failure modes:

1. the handle is not idempotent (`Consumer::close()` / `Producer::close()` send
   a second Unsubscribe/DeletePublisher), and
2. `Connection` keeps an append-only/stale handle map and re-invokes `close()`
   on a handle the user already closed.

Both tests drive **real** `Producer` / `Consumer` objects created through
`Connection::createProducer()` / `createConsumer()` over a mocked
`StreamConnection`, exercising the real user sequence: *user closes handle →
`Connection::close()`*.

Producer test (`tests/Client/ConnectionTest.php:1038-1090`):

- After `$producer->close()`: asserts `isClosed() === true`, exactly one
  `DeletePublisherRequestV1` on the wire, and `producersOf($connection) === []`
  (map cleanup — failure mode 2).
- After `$connection->close()`: `$deletes` **unchanged** (`=== 1`) and the
  connection still runs its own `CloseRequestV1` exchange (`$closes === 1`).
- After a second `$producer->close()`: `$deletes` **unchanged** (`=== 1`) —
  failure mode 1.

Consumer test (`tests/Client/ConnectionTest.php:1092-1148`) is the symmetric
version with `SubscribeRequestV1` / `UnsubscribeRequestV1` /
`consumersOf($connection)`.

The `$closes === 1` assertion is important: without it, "no further
Delete/Unsubscribe" could pass vacuously if `Connection::close()` never ran.
It confirms the shutdown path really executed. `assertSame(1, $declares)` /
`assertSame(1, $subscribes)` confirm the setup, so the counters cannot start
wrong and pass trivially.

The "does not throw" half of the guarantee is covered structurally: an unhandled
exception fails the test as an error. Note (see findings-review.md R2) that the
mock never returns an error response code, so the in-test path cannot throw a
`ProtocolException`; the test instead pins the root cause — *no second request
is sent* — which is the stronger and correct assertion.

## 2. Meaningfulness — independent mutation testing

Re-run by the reviewer in a throwaway copy
(`/private/var/folders/.../opencode/rs463mut`, excluded `.git`, no commit, source
restored byte-for-byte afterwards):

| # | Mutation | Test run | Result |
|---|---|---|---|
| M1 | Remove `$closed` early-return in `Producer::close()` (`Producer.php:540-542`) | producer test | **FAIL** at `ConnectionTest.php:1089` — "A second producer close() must not send another DeletePublisher" (2 ≠ 1) |
| M2 | Remove `$closed` early-return in `Consumer::close()` (`Consumer.php:794-796`) | consumer test | **FAIL** at `ConnectionTest.php:1147` — "A second consumer close() must not send another Unsubscribe" (2 ≠ 1) |
| M3a | Remove `unset($this->producers[$id])` in `Connection::newProducer()`'s `onClose` (`Connection.php:986-988`) | producer test | **FAIL** at `ConnectionTest.php:1074` — producer map not empty |
| M3b | Remove `unset($this->consumers[$id])` in `Connection::newConsumer()`'s `onClose` (`Connection.php:1108-1110`) | consumer test | **FAIL** at `ConnectionTest.php:1132` — consumer map not empty |

All four mutations were caught, each at a distinct assertion. This matches the
coder's claims in `findings-coder.md` but was reproduced independently rather
than taken on trust. It confirms both failure modes are genuinely covered:
guard mutations are caught by the final double-close, map-cleanup mutations by
the post-close map assertion.

## 3. Flakiness / over-mocking / trivial assertions

- **Flakiness: none.** Deterministic mocks; no timers, sockets, sleeps, ordering
  dependence, or shared state between tests. New tests are additive.
- **Over-mocking: appropriate.** Real handle + real `Connection` over a mocked
  `StreamConnection`; the `StreamConnection` is the correct seam. The
  `sendMessage` / `request` capture counts exactly the frame types under test.
- **Triviality: none.** No assertion can pass without the corresponding
  behaviour; every counter is anchored by an initial `assertSame(1, …)`. The
  final double-close assertions are what actually pin the idempotency guard
  (the map assertion empties the map first, so the guard is not exercised by the
  `Connection::close()` step alone). This division is deliberate and correct.
- **Nit (not a defect):** the mocks return `CloseResponseV1` for *every*
  `readMessage()` / `request()` call regardless of the expected response type
  (DeclarePublisher/Subscribe). `Producer::declare()` ignores the read result and
  `Consumer::sendSubscribe()` ignores the `request()` result, so this is benign
  today, but adding response-type validation to those paths later would break
  the mocks. See findings-review.md R2.

## 4. Lint / static analysis / tests (run locally, PHP 8.5.10)

| Gate | Result |
|---|---|
| `./vendor/bin/phpunit tests/Client/ConnectionTest.php` | **OK (50 tests, 4971 assertions)** |
| `./vendor/bin/phpunit --testsuite unit` | **OK (1244 tests, 8817 assertions)** |
| `composer lint` (PHPCS PSR-12 + Rector dry-run + PHPStan level 9 + kb-lint + docs links + suite coverage) | **OK** — PHPCS 281/281, Rector OK, PHPStan 275/275 "No errors", 0 warnings, suite coverage OK |

Both new tests are inside the `unit` suite (`test:suite-coverage` passes).
Imports are all used (`DeclarePublisherRequestV1`, `DeletePublisherRequestV1`,
`SubscribeRequestV1`, `UnsubscribeRequestV1`), grouped alphabetically with the
existing request imports; no PSR-12 or PHPStan issues.

## 5. Proof-of-work docs

`code-decision-1.md` and `findings-coder.md` accurately describe the approach,
the rejected alternatives, and the mutation results. The cited source locations
for the production behaviour are all correct:
`Consumer.php:122,794-797`, `Producer.php:80,540-543`,
`Connection.php:815-837,986-991,1108-1113`.

One inaccuracy: the citation of the pre-existing id-reclamation tests as
`ConnectionTest.php:915/959/987` is stale — those tests now live at
**919/963/991** after this diff added four `use` lines. (Confirmed both against
`main` and HEAD.) Low, documentation-only.

## Findings index (see `findings-review.md` for dispositions)

| # | File:line | Severity | Summary |
|---|---|---|---|
| R1 | `docs/proof_of_work/463-close-idempotency-test/code-decision-1.md:20` | low | Stale line numbers 915/959/987 → 919/963/991 for the id-reclamation tests |
| R2 | `tests/Client/ConnectionTest.php:1045-1046,1099-1100` | nit | Mocks return `CloseResponseV1` for every `readMessage`/`request`, regardless of expected response type (benign today) |

**Code and tests look good; no code findings to fix.**
