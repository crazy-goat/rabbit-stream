# Findings — #463 close idempotency regression test

## Status of the underlying bug

The production fix for #463 is **already in `main`**, delivered by the
id-reclamation work tracked as PR #504 / #388. The `$closed` guard and the
`onClose` map cleanup are present at:

| Location | What it does | State |
|---|---|---|
| `src/Client/Consumer.php:122,794-797` | `$closed` flag; second `close()` returns early | present |
| `src/Client/Producer.php:80,540-543` | `$closed` flag; second `close()` returns early | present |
| `src/Client/Connection.php:986-991` | producer `onClose` unsets `$producers[$id]` | present |
| `src/Client/Connection.php:1108-1113` | consumer `onClose` unsets `$consumers[$id]` | present |
| `src/Client/Connection.php:815-837` | `close()` iterates only the (now live-only) maps | present |

This cycle therefore adds **only the missing regression test**; no production
code changed. That is the right outcome — the issue's acceptance criteria are
satisfiable without a code change, and touching working close paths to
"make room" for a test would have been unjustified.

## Obstacles

- `Connection::createProducer()` / `createConsumer()` return
  `ProducerInterface` / `ConsumerInterface`, neither of which declares
  `isClosed()`. PHPStan level 9 rejects calling it on the interface. Solved
  with `assertInstanceOf(Producer::class, …)` / `assertInstanceOf(Consumer::class, …)`,
  which narrows the type and doubles as documentation.
- `Connection::close()` insists on a `CloseResponseV1` from `readMessage()`
  before closing the socket, so the mock's `readMessage` must return one; a
  bare `->method('readMessage')` (as the older reclaim helpers use) would make
  `Connection::close()` throw `UnexpectedResponseException`. The new tests use
  `willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1())`, which
  also satisfies the `readMessage()` calls made by the producer's constructor
  and `close()`.

## Meaningfulness check (mutation testing)

To prove the tests fail for the right reasons, three temporary mutations were
applied to production code, the relevant test run, and the file restored with
`git checkout`:

| Mutation | Expected failure | Observed |
|---|---|---|
| Remove `$closed` guard in `Producer::close()` | 2nd `DeletePublisher` | FAIL — "A second producer close() must not send another DeletePublisher" (2 ≠ 1) |
| Remove `$closed` guard in `Consumer::close()` | 2nd `Unsubscribe` | FAIL — "A second consumer close() must not send another Unsubscribe" (2 ≠ 1) |
| Remove `unset($this->producers[$id])` in `Connection::newProducer()`'s `onClose` | stale map entry | FAIL — producers map not empty at `ConnectionTest.php:1074` |

Each mutation targeted exactly one of the two failure modes the issue names
("iterated a stale handle map" / "handle lacked the idempotency guard"), and
each was caught. Production files were restored byte-for-byte afterwards
(`git status` showed only `tests/Client/ConnectionTest.php` modified).

## Bugs / weak spots noticed

None in the close paths — the fix is correct. Two adjacent observations, both
out of scope and left untouched:

1. `ConsumerInterface` / `ProducerInterface` expose `close()` but not
   `isClosed()`, even though the concrete classes do. A caller holding the
   interface cannot check closed state without an `instanceof` downcast.
   Adding it to the interfaces would be a BC break for external implementors
   and is deliberately avoided elsewhere (same reasoning as #522's
   `getLostConfirmCount()`), so this is consistent, not a defect.
2. `Connection::close()` catches every `\Throwable` from a handle close and
   only logs a warning. Now that handles are removed from the map on user
   close, this catch effectively only guards genuinely-flaky live handles; the
   "re-close on shutdown" source of those warnings described in #463 is gone.
   Good — but it means the tests for the warning path
   (`testCloseLogsWarningWhenProducerCloseThrows`) inject a throwing mock
   rather than reproducing the real bug, which is fine.

## Test / verification notes

- `./vendor/bin/phpunit tests/Client/ConnectionTest.php` → OK (50 tests).
- `./vendor/bin/phpunit --testsuite unit` → OK (1244 tests, 8817 assertions).
- `composer lint` → OK (PHPCS PSR-12, Rector dry-run, PHPStan level 9,
  kb-lint, docs links, suite-coverage gate).
