# Review Findings — Issue #415 (Producer public API PHPDoc and @throws)

Round 1. Format: `file:line | what is wrong | severity | what happened to it`.
Append-only for subsequent rounds.

Reviewed HEAD: `a58de46` (`feature/issue-415-producer-phpdoc`, diff vs `main`).
Scope: docblocks in `src/Client/Producer.php`, `src/Contract/ProducerInterface.php`,
`docs/en/api-reference/producer.md`, `docs/en/guide/publishing.md`,
`docs/en/examples/named-producer-deduplication.md`, `CHANGELOG.md` and the new
reflection gate `tests/Client/ProducerDocblockTest.php`. No production logic changed.

## Findings on this diff

- `src/Client/Producer.php:685` + `docs/en/api-reference/producer.md:349-358,370`
  | The new `getLastPublishingId()` prose says "An anonymous producer starts at
  id 1", and the API-reference example now carries an explicit `// Anonymous
  producer` label followed by `$id1 = … // 1`, `$id2 = … // 2`, `$id3 = … // 5
  (2 + 3 messages)` and the note "Publishing IDs start at 1 for unnamed
  producers". The implementation is 0-based: `publishingId` starts at 0
  (`Producer.php:65`), `send()` uses it and only then increments
  (`:378-383`), and `getLastPublishingId()` returns `publishingId - 1`
  (`:693`). So after `send('Message 1')` the last id is **0**, after two sends
  it is **1**, and after `sendBatch(['A','B','C'])` (ids 2,3,4) it is **4** — not
  1/2/5. This is pinned by the existing tests (`tests/Client/ProducerTest.php:289`
  asserts `0`, `:292` asserts `1`; `tests/E2E/ProducerTest.php:86` asserts `0`,
  `:89` asserts `2`). The claim also contradicts this PR's own
  `code-decision-1.md:56` ("Only an anonymous producer starts at
  `publishingId = 0`"). The numeric comments were already wrong on `main`, but
  this PR edited this exact section, added the `// Anonymous producer` label
  that attributes them to the anonymous path, and introduced the same false
  statement in the class docblock — so the deliverable ("document the
  publishing-id behaviour accurately") is not met for the anonymous case.
  | **medium** | Open. Fix the class docblock and the example/notes to 0-based
  (first id 0, first `getLastPublishingId()` 0, batch last id 4; "Publishing IDs
  start at 0 for unnamed producers"). Alternatively, if 1-based is intended,
  that is a production-behaviour change and must not ride in a docs PR.

- `src/Client/Producer.php:661-677` (also `src/Contract/ProducerInterface.php:85`)
  | `waitForConfirms()` declares `TimeoutException`, `ConnectionException` and
  `DeserializationException`, but the drain it runs (`waitForConfirms()` →
  `drainUntilZero()` → `StreamConnection::readLoop()`) dispatches every
  server-push frame through `dispatchServerPush()`, whose handlers
  (`handlePublishConfirm`/`handlePublishError`/`handleMetadataUpdate`) call
  `…ResponseV1::fromStreamBuffer()` → `validateKeyVersion()`, which throws
  `ProtocolException` on a key or version mismatch (`CommandTrait.php:39-48`).
  `readLoop()` has no `try/catch` around `dispatchServerPush()`, so the
  `ProtocolException` propagates. The sibling publish methods document exactly
  this path ("or a frame has an unexpected version or command"), and
  `code-decision-1.md:42-43` explicitly enumerates this method's throws without
  `ProtocolException` — so the omission is a genuine, internally inconsistent
  gap. | **low** | Open. Add `@throws ProtocolException` to `waitForConfirms()`
  in both the class and the interface (reachability requires a malformed /
  unexpected broker frame, hence low rather than medium).

- `src/Client/Producer.php:533-534` (also the API reference `producer.md:240`)
  | `close()` documents `@throws InvalidArgumentException If the serialized
  DeletePublisher request exceeds the negotiated outgoing frame size`. A
  `DeletePublisherRequestV1` payload is key(2)+version(2)+correlation(4)+
  publisherId(1) ≈ 9 bytes, far below any broker-negotiated `frame_max`
  (`StreamConnection::sendFrame()` only rejects a payload above that cap). The
  throw is reachable in principle but not in practice, so it is over-declared.
  | **low** | Open. Drop the `InvalidArgumentException` tag from `close()` (and
  its API-reference exceptions list) or, if kept, state the (unreachable) size
  precondition. Everything else in `close()`'s set — including
  `ProtocolException`/`DeserializationException` via the drain — is genuinely
  reachable.

- `src/Client/Producer.php:743-758` | `querySequence()`'s `@throws
  InvalidArgumentException If this is an anonymous producer (no name)` is only
  true for `name === null` (`:756`). `initializePublishingId()` treats an empty
  string as anonymous too (`:268`, `name !== null && name !== ''`), and the new
  constructor `@param $name` documents `null (or "")` as anonymous
  (`:110-115`). A producer created with `name: ''` is therefore anonymous
  behaviourally but `querySequence()` does **not** throw for it; it sends a
  query with an empty name. | **low** | Open (edge case). Either make
  `querySequence()` reject `''` as well (`$this->name === null || $this->name
  === ''`) or tighten the tag to "If this producer has no name (`null`)". Note
  the underlying code asymmetry (`:268` vs `:756`) predates the diff.

- `tests/Client/ProducerDocblockTest.php:94-129` | The gate catches a
  documented `@throws` that names a non-existent class, but it does **not**
  require any `@throws` to be present. Verified in a throwaway copy: stripping
  every `@throws` tag from `send()`'s docblock leaves all three tests green.
  | **low** | Open; accepted-by-design limitation (the test's own docblock in
  `Producer.php`/the class header explains that `@throws` accuracy cannot be
  asserted reflexively). Recording it so nobody assumes the gate protects the
  `waitForConfirms()` gap above; a curated per-method `@throws` allow-list
  is the only mechanical way to catch that class of omission.

- `docs/proof_of_work/415-producer-phpdoc/findings-coder.md:52` | Claims the
  unit suite ends at "1240 tests, 8794 assertions"; measured 1240 tests, **8790**
  assertions on this machine (PHP 8.5.10). | **nit** | Open. Not source; counts
  vary by PHP version (see the #478 round-2 precedent). No action needed.

## Non-findings (verified, recorded as evidence)

- `querySequence()` really throws `InvalidArgumentException` for an unnamed
  (`null`-name) producer: `Producer.php:756-758`. `waitForConfirms()` really
  throws `TimeoutException` when `drainUntilZero()` returns false:
  `:672-676`. Both tags are accurate for the normal cases.
- Named-producer `getLastPublishingId()` pre-send behaviour is documented and
  true: `initializePublishingId()` sets `publishingId = sequence + 1`
  (`:266-272`), `getLastPublishingId()` returns `sequence` (`0` when nothing
  stored) before any `send()` (`:691-694`). `docs/en/guide/publishing.md:256-260`
  and `docs/en/examples/named-producer-deduplication.md` state it correctly.
- `send()`'s payload contract (single AMQP 1.0 Data section, `getBody()` returns
  the same string, pre-encoded bytes must use the low-level encoder) matches
  `AmqpMessageEncoder::encodeDataSection($message)` at `:380`.
- `sendWithFilter()`'s corrected v1/v2 claim matches the code: `null` filter →
  `PublishRequestV1` (`:434-439`); non-null filter without negotiated Publish v2
  → `ProtocolException` (`:426-431`); non-null filter with v2 →
  `PublishRequestV2` (`:441-448`).
- `close()`, `isClosed()`, `createProducer()`'s documented signature, the
  `ProducerInterface` docblocks and the `CHANGELOG.md` entry do not contradict
  the implementation.
- The gate is not vacuous for the checks it claims: in a throwaway copy, removing
  a public method's docblock, removing its prose description, removing an
  `@param`, making an `@return` description-less, or adding an `@throws` naming a
  non-existent class each produced a test failure (A–E above). The generic
  spaced-type regression test (`ProducerDocblockTest.php:137-158`) is identical
  to the #416 gate and passes.

## Local QA (HEAD `a58de46`, clean tree)

- `composer lint` — passed (PHPCS PSR-12, Rector dry-run 0 changes, PHPStan
  level 9, kb-lint, docs-link check, test-suite coverage).
- `./vendor/bin/phpunit --testsuite unit` — passed (1240 tests, 8790 assertions,
  ~9.5 s).
- `./vendor/bin/phpunit tests/Client/ProducerDocblockTest.php` — passed
  (3 tests, 8 assertions).

## Summary

- high: 0
- medium: 1 (anonymous-producer publishing-id docs/example contradict the 0-based
  implementation and the existing unit/E2E tests)
- low: 4 (`waitForConfirms()` missing `ProtocolException`; `close()` over-declared
  `InvalidArgumentException`; `querySequence()` empty-name inconsistency; gate
  does not require any `@throws`)
- nit: 1 (assertion count in `findings-coder.md`)

**Verdict: not converged — 1 medium and 4 low remain open.**

---

## Round 2 — coder response

All five findings and the nit are addressed. See
`code-decision-2.md` for the reasoning. Changes on top of `a58de46`:

- **medium — anonymous publishing IDs documented 1-based → fixed.**
  `src/Client/Producer.php` `getLastPublishingId()` docblock now says the first
  anonymous publish uses id `0` (returns `null` before it, `0` after).
  `docs/en/api-reference/producer.md` example is now `id1 // 0`, `id2 // 1`,
  `id3 // 4 (1 + 3 messages)`, and the note reads "Publishing IDs start at 0
  for unnamed producers (the first `send()` uses id `0`, …)". Cross-checked
  against `tests/Client/ProducerTest.php:289` (`0`) / `:292` (`1`) and
  `tests/E2E/ProducerTest.php:86` (`0`) / `:89` (`2`). The CHANGELOG #415 entry
  was corrected too.
- **low — `waitForConfirms()` missing `ProtocolException` → fixed.**
  Added `@throws ProtocolException` to `src/Client/Producer.php` (class),
  `src/Contract/ProducerInterface.php` and `docs/en/api-reference/producer.md`
  (the drain reaches `readLoop()` → `dispatchServerPush()` →
  `validateKeyVersion()`), and to the CHANGELOG's #415 entry.
- **low — `close()` over-declared `InvalidArgumentException` → fixed.**
  Removed the tag from the class docblock, `ProducerInterface::close()` and the
  API-reference exceptions list. A `DeletePublisherRequestV1` payload cannot
  exceed `frame_max`.
- **low — `querySequence()` empty-name inconsistency → fixed (behaviour).**
  `querySequence()` now rejects `$this->name === ''` as well as `null`, matching
  `initializePublishingId()` (`name !== null && name !== ''`). Docblock text in
  the class, interface and API reference now says "a `null` or `""` name". New
  test `ProducerTest::testQuerySequenceThrowsForEmptyNameProducer`.
- **low — reflection gate does not require any `@throws` → fixed.**
  `tests/Client/ProducerDocblockTest.php` gained
  `testEveryThrowingPublicMethodDeclaresItsExpectedThrows` with a curated
  `EXPECTED_THROWS` map (method → exact `@throws` set) covering every public
  method. Removing `ProtocolException` from `waitForConfirms()` was verified to
  fail the test.
- **nit — assertion count → fixed.** `findings-coder.md` now records 8790.

### Local QA (round 2, working tree on top of `a58de46`)

- `./vendor/bin/phpunit --testsuite unit` — passed (1242 tests, 8797
  assertions).
- `./vendor/bin/phpunit tests/Client/ProducerDocblockTest.php` — passed (4
  tests, 9 assertions).
- `composer lint` — passed (PHPCS PSR-12, Rector dry-run 0 changes, PHPStan
  level 9, kb-lint, docs links, suite coverage).
- `./run-e2e.sh` — passed (147 tests, 3059 assertions).
- Mutation check: stripping `@throws ProtocolException` from
  `waitForConfirms()` fails the new gate.

**Verdict: converged — all round-1 findings resolved.**

