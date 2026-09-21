# Code decision — test(connection): cross-object close idempotency (#463)

## Scope of this cycle

Issue #463 asked for two things: an idempotent `Consumer::close()` /
`Producer::close()`, and a `Connection::close()` that does not re-close a
handle the user already closed. **Both production fixes already landed** as
part of the id-reclamation work (PR #504 / #388):

- `Consumer.php:122` / `Producer.php:80` — a `private bool $closed` flag;
  `close()` returns early when set (`Consumer.php:794-797`,
  `Producer.php:540-543`).
- `Connection.php:986-991` / `Connection.php:1108-1113` — the `onClose`
  callback passed to each handle unsets it from `$producers` / `$consumers`
  the moment the user closes it, so `Connection::close()`
  (`Connection.php:815-837`) only ever iterates live handles.

What was missing, and what this cycle adds, is a **direct regression test for
the exact cross-object sequence** described in the issue. The existing tests
cover each half in isolation (`ConnectionTest.php:915/959/987` assert id
reclamation; `ConsumerTest.php:1389` / `ProducerTest.php:858` assert a double
handle `close()` is a no-op), but no test drove *user closes handle → then
`Connection::close()`* through the real `Connection` + real handle over the
mocked `StreamConnection`.

## Approach taken

Two tests in `tests/Client/ConnectionTest.php`, next to the existing
handle/connection close tests:

1. `testConnectionCloseDoesNotReCloseAProducerTheUserAlreadyClosed`
2. `testConnectionCloseDoesNotReCloseAConsumerTheUserAlreadyClosed`

Each test:

1. builds a `StreamConnection` mock that records outgoing frames by type
   (`DeclarePublisherRequestV1` / `DeletePublisherRequestV1` /
   `SubscribeRequestV1` / `UnsubscribeRequestV1` / `CloseRequestV1`);
2. creates a **real** `Producer` / `Consumer` through
   `Connection::createProducer()` / `createConsumer()`;
3. calls the handle's `close()`, asserting exactly one Delete/Unsubscribe,
   `isClosed() === true`, and that the handle is gone from the connection map
   (`producersOf()` / `consumersOf()`);
4. calls `Connection::close()`, asserting the Delete/Unsubscribe counter is
   **unchanged** and that the connection still performs its own
   `CloseRequestV1` exchange (so we know the connection close really ran);
5. calls the handle's `close()` a second time (after the connection is
   closed) and asserts it is still a no-op.

This follows the file's established conventions exactly: the inline
`createMock(StreamConnection::class)` + `willReturnCallback` capture pattern
already used by `testCloseSendsCloseRequestBeforeClosingSocket` and the
`mockForProducers()` / `mockForConsumers()` helpers, the
`createConnectionWithMock()` reflection factory, and the existing
`producersOf()` / `consumersOf()` inspectors. No new harness was introduced.

`assertInstanceOf(Producer::class, …)` / `(Consumer::class, …)` is needed
because `createProducer()` / `createConsumer()` return the `*Interface` types,
which do not declare `isClosed()`; the assertion narrows the type for PHPStan
level 9 while also documenting the concrete class under test.

## Why this is a meaningful test

The tempting weak version — only counting Delete/Unsubscribe — would still
pass if `Connection` kept a stale handle map but the handle's guard held,
because the re-close would be swallowed by the guard. The map assertions
(step 3) close that gap: they fail if the `onClose` cleanup is removed.
Conversely, the count-unchanged assertion fails if the guard is removed and the
map still drives a second close. Mutation testing was run to confirm both
directions (see `findings-coder.md`).

## Rejected alternatives

- **A single combined producer+consumer test.** Two focused tests give a
  precise failure name per handle type and each can be mutated independently.
- **Mocking `Producer`/`Consumer` and asserting `close()` call counts**, as the
  existing `testCloseClosesAllTrackedProducers` does. That tests the
  `Connection` iteration but not the real wire behavior: it cannot tell a
  second `DeletePublisher` from a `close()` that returned early, and it would
  pass even if the guard were missing. Real handles were needed for a
  regression test of the bug.
- **Injecting a fake already-closed handle into `$producers`.** It would test
  the connection loop against a hand-built state, not the real "user closes,
  then connection closes" path this issue is about.
- **A production change to remove the map cleanup or the guard.** Out of
  scope: both behaviours are correct and shrink-only; the issue is a missing
  test, not a defect. No production code was touched.

## Verification

- `./vendor/bin/phpunit tests/Client/ConnectionTest.php` → OK (50 tests).
- `./vendor/bin/phpunit --testsuite unit` → OK (1244 tests).
- `composer lint` → OK (PHPCS PSR-12, Rector dry-run, PHPStan level 9,
  kb-lint, docs links, suite coverage).
