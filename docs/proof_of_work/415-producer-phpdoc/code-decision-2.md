# Code decision 2 — review round 1 fixes (#415)

Addresses every finding in
`docs/proof_of_work/415-producer-phpdoc/review-1.md` and
`findings-review.md`. Base: `a58de46` (`feature/issue-415-producer-phpdoc`).

## 1. Anonymous publishing IDs are 0-based (medium)

The review was right: the implementation, the unit tests and the E2E tests are
all 0-based, while the new class docblock and the API-reference example claimed
1-based.

- `Producer::$publishingId` starts at `0` (`src/Client/Producer.php:65`);
  `send()` publishes `$this->publishingId` and only then increments
  (`:378-383`); `getLastPublishingId()` returns `publishingId - 1` (`:693`).
- Pinned by `tests/Client/ProducerTest.php:289` (`0`) / `:292` (`1`) and
  `tests/E2E/ProducerTest.php:86` (`0`) / `:89` (`2`).

So the documented anonymous sequence is: first `send()` uses id `0`; after two
single sends `getLastPublishingId()` is `1`; after `sendBatch(['A','B','C'])`
(ids 2,3,4) it is `4`. The class docblock, the API-reference example and note,
and the CHANGELOG #415 entry were corrected. Changing the implementation was
never on the table: that is a production behaviour change and cannot ride in a
docs PR (and dedup semantics are pinned by E2E).

## 2. `waitForConfirms()` can raise `ProtocolException` (low)

`waitForConfirms()` → `drainUntilZero()` → `StreamConnection::readLoop()` →
`dispatchServerPush()` → `handlePublishConfirm()`/`handlePublishError()`/
`handleMetadataUpdate()` → `…ResponseV1::fromStreamBuffer()` →
`validateKeyVersion()`, which throws `ProtocolException` on a key/version
mismatch. `readLoop()` does not catch it. Added the tag to the class, the
interface and the API reference (the publish methods already documented the
same path).

## 3. `close()` no longer over-declares `InvalidArgumentException` (low)

A `DeletePublisherRequestV1` payload is ≈9 bytes; `StreamConnection::sendFrame()`
only rejects a payload above the negotiated `frame_max`. The tag was removed
from the class docblock, `ProducerInterface::close()` and the API-reference
exceptions list.

## 4. `querySequence()` treats `''` as anonymous (low, behaviour fix)

`initializePublishingId()` already treats `name === ''` as anonymous
(`name !== null && name !== ''`), but `querySequence()` only rejected `null`, so
a `name: ''` producer queried the broker with an empty name. The two are now
consistent: `querySequence()` rejects `null` **and** `''` with the same
`InvalidArgumentException`. This is the minimal fix (one condition) and it makes
the `@throws` text true. The constructor's `@param` already documents `""` as
anonymous, so the class is now anonymous everywhere for `''`.

Rejected alternative: tightening the tag to "`null` only". That would leave the
public method accepting a name the rest of the class rejects and would document
an inconsistency as intended behaviour. A regression test,
`ProducerTest::testQuerySequenceThrowsForEmptyNameProducer`, pins it.

## 5. The reflection gate now pins the `@throws` sets (low)

`tests/Client/ProducerDocblockTest.php` gained
`testEveryThrowingPublicMethodDeclaresItsExpectedThrows` and a curated
`EXPECTED_THROWS` map (method name → exact sorted `@throws` classes) covering
every public method, including the accessors with an empty set. It compares the
map against the docblocks with `assertSame`, so:

- removing any expected tag fails (verified by stripping `ProtocolException`
  from `waitForConfirms()` — the test fails);
- adding an undocumented tag fails;
- adding a public method without a map entry fails.

The map is the mechanical half of the existing "accuracy cannot be asserted
reflexively" caveat. If a real throw path changes, the map must be updated
deliberately rather than the tag silently disappearing.

## 6. Assertion-count nit

`findings-coder.md` now records 8790 (the measured value) instead of 8794, with
a note that counts vary by PHP version.

## Verification

- `./vendor/bin/phpunit --testsuite unit` — 1242 tests, 8793 assertions, OK.
- `./vendor/bin/phpunit tests/Client/ProducerDocblockTest.php` — 4 tests, 9
  assertions, OK.
- `composer lint` — PHPCS PSR-12, Rector dry-run 0 changes, PHPStan level 9,
  kb-lint, docs links, suite coverage: all pass.
- `./run-e2e.sh` — 147 tests, 3059 assertions, OK.
- Gate mutation check as above.

No gate was weakened; the only production change is the one-condition
`querySequence()` fix, and it is covered by a new unit test.
