# Review — Round 1 — Issue #415 (Producer public API PHPDoc and @throws)

**Reviewer:** review (deep, evidence-backed)
**Branch:** `feature/issue-415-producer-phpdoc`
**Base:** `main` (HEAD `a58de46`)
**Files in diff:** `src/Client/Producer.php` (docblocks + 2 annotation-only imports),
`src/Contract/ProducerInterface.php`, `docs/en/api-reference/producer.md`,
`docs/en/guide/publishing.md`, `docs/en/examples/named-producer-deduplication.md`,
`CHANGELOG.md`, `tests/Client/ProducerDocblockTest.php`, plus two POW docs.

## Earlier-round findings

`docs/proof_of_work/415-producer-phpdoc/findings-review.md` did not exist before
this round; the only prior artifact is `findings-coder.md`. This is round 1.

---

## Overall verdict: **CHANGES REQUESTED**

The documentation pass is broad, well-written and mostly accurate, and the new
reflection gate is a faithful #414/#416-style guard that genuinely fails when a
docblock, prose description, `@param` or described `@return` disappears (verified
by mutation). No production logic changed; `composer lint` and the unit suite are
green.

One **medium** finding and four **low** findings remain. The medium one is a
factual error in the exact area the issue was filed to fix: the anonymous-producer
publishing-id behaviour is documented as 1-based while the implementation, the
existing unit tests and the existing E2E tests are 0-based.

---

## 1. `@throws` accuracy — cross-checked against the real throw paths

I traced every public method through `ensureDeclared()`, `applyBackpressure()`,
`drainPendingConfirms()`/`drainUntilZero()`, `querySequence()`, `sendBatch()`,
`readLoop()` and the `DeletePublisher` exchange, then through `StreamConnection`.

**Correct sets (no finding):**

| Method | Declared | Genuinely reachable |
|---|---|---|
| `__construct` | `InvalidArgumentException`, `Connection`, `Deserialization`, `Protocol`, `Timeout`, `UnexpectedResponse` | yes: `redeclareTimeout < 0` `:157`; `declare()`→`sendMessage()`/`readMessage()`; `initializePublishingId()`→`querySequence()` |
| `send` / `sendBatch` / `sendWithFilter` | `Connection`, `Deserialization`, `InvalidArgument`, `Protocol`, `Timeout` | yes: `ensureDeclared()`→`request()`; `applyBackpressure()`→`readLoop()`; `sendMessage()`→`sendFrame()` cap |
| `close` | `Connection`, `Deserialization`, `InvalidArgument`, `Protocol`, `Timeout` | all reachable except `InvalidArgument` (see §3) |
| `querySequence` | `InvalidArgument`, `Connection`, `Deserialization`, `Protocol`, `Timeout`, `UnexpectedResponse` | yes: `:756-764` plus the exchange |
| `isStale`, `getRedeclareCount`, `isClosed`, `getLastPublishingId`, `getPendingConfirms`, `getLostConfirmCount` | none | correct — pure accessors |

`querySequence()` really throws `InvalidArgumentException` for a `null`-named
producer (`:756-758`), and `waitForConfirms()` really throws `TimeoutException`
when `drainUntilZero()` returns false (`:672-676`). Both are accurate.

**Finding — `waitForConfirms()` is missing `ProtocolException`.** The drain runs
`StreamConnection::readLoop()`, which dispatches every server-push frame through
`dispatchServerPush()`. `handlePublishConfirm()`/`handlePublishError()`/
`handleMetadataUpdate()` call `…ResponseV1::fromStreamBuffer()` →
`validateKeyVersion()`, which throws `ProtocolException` on a key or version
mismatch (`src/Trait/CommandTrait.php:39-48`), and `readLoop()` does not catch
it. The publish methods document this path ("a frame has an unexpected version or
command"); `waitForConfirms()` does not, and `code-decision-1.md:42-43`
enumerates its throws without it. Reachability requires a malformed/unexpected
broker frame, so severity **low**, but it is a real omission in the deliverable.

**Finding — `close()` over-declares `InvalidArgumentException`.** A
`DeletePublisherRequestV1` payload is ≈9 bytes; `sendFrame()` only rejects a
payload above the negotiated `frame_max`. The tag (and its API-reference entry)
describe a throw that cannot occur in practice. Severity **low**. Everything else
in `close()`'s set is reachable, including `ProtocolException`/
`DeserializationException` via the confirm drain.

**Finding — `querySequence()`'s unnamed condition is incomplete.**
`initializePublishingId()` treats `name === ''` as anonymous (`:268`), and the
constructor `@param` documents `null (or "")` as anonymous, but `querySequence()`
only rejects `name === null` (`:756`). The `@throws` text ("If this is an
anonymous producer (no name)") is therefore false for a `name: ''` producer —
it does not throw. Severity **low** (edge case; the underlying asymmetry
predates the diff).

## 2. The medium finding — anonymous publishing IDs are documented 1-based

`src/Client/Producer.php:685`:

> "An anonymous producer starts at id 1 and returns null until its first send()."

`docs/en/api-reference/producer.md:349-358` (the section this PR edited, now
labelled `// Anonymous producer`): `id1 // 1`, `id2 // 2`, `id3 // 5`; and
`:370`: "Publishing IDs start at 1 for unnamed producers".

The implementation is 0-based:

- `private int $publishingId = 0;` (`:65`);
- `send()` builds `new PublishedMessage($this->publishingId, …)` and only then
  increments (`:378-383`);
- `getLastPublishingId()` returns `publishingId === 0 ? null : publishingId - 1`
  (`:691-694`).

So for the documented anonymous sequence: after `send('Message 1')` the id is
**0**; after `send('Message 2')` it is **1**; after `sendBatch(['A','B','C'])`
(ids 2,3,4) it is **4**. This is exactly what the existing tests assert —
`tests/Client/ProducerTest.php:289` (`0`) and `:292` (`1`), and
`tests/E2E/ProducerTest.php:86` (`0`) / `:89` (`2`) — and it directly contradicts
this PR's own `code-decision-1.md:56` ("Only an anonymous producer starts at
`publishingId = 0`").

The numeric comments pre-date the branch, but the PR: (a) added the
`// Anonymous producer` label that now attributes them to the anonymous path,
(b) left the "start at 1" note standing, and (c) introduced the same false
statement into the new class docblock. Since the issue's second acceptance
criterion is to explain publishing-id behaviour, the anonymous half is wrong.
Fix the docblock and the example/notes to 0-based, or (if 1-based is the real
intent) file a production bug — but that behaviour cannot change in a docs PR.

## 3. `ProducerInterface` / docs cross-check

- `ProducerInterface` docblocks mirror the class and do not contradict the
  implementation. The interface's `waitForConfirms()` shares the same missing
  `ProtocolException` tag as the class.
- `docs/en/api-reference/producer.md`: the corrected `sendWithFilter()` v1/v2
  claim is accurate (null filter → v1 `:434-439`; non-null without negotiated v2
  → `ProtocolException` `:426-431`; non-null with v2 → `PublishRequestV2`
  `:441-448`). The `close()` exception list, the new `isClosed()` section and the
  `createProducer()` signature all match the code.
- `docs/en/guide/publishing.md:256-260` and
  `docs/en/examples/named-producer-deduplication.md` correctly describe the
  named-producer pre-send behaviour.
- `CHANGELOG.md` has a correct `### Changed` entry under `[Unreleased]`.

## 4. The reflection gate is not vacuous (mutations in a throwaway copy)

I copied the repo (excluding `.git`) to a temp dir, confirmed the autoloader
loaded the copy's `Producer.php`, and mutated the copy one change at a time:

| Mutation | Result |
|---|---|
| A. Remove `isClosed()`'s docblock | **FAIL** (`testEveryPublicMethodIsDocumented`) |
| B. Replace `isStale()`'s docblock with a bare `@return` | **FAIL** (missing description) |
| C. Remove `@param ?float $timeout` from `send()` | **FAIL** (missing `@param`) |
| D. Make `getLastPublishingId()`'s `@return` description-less | **FAIL** (missing `@return` prose) |
| E. Add `@throws TotallyBogusException` to `isClosed()` | **FAIL** (`testEveryDocumentedThrowsNamesARealClass`) |
| F. Strip **every** `@throws` from `send()` | **pass** — the gate does not require any `@throws` tag |

A–E confirm the gate does what its own docblock claims. F is an
accepted-by-design limitation (accuracy cannot be asserted reflexively), but it
means the gate would not have caught the `waitForConfirms()` gap; recorded as a
low finding rather than a defect. The generic spaced-type regression test
(`testReturnDescriptionHandlesGenericTypesWithSpaces`) is carried over from #416
and passes.

## 5. Local QA

| Command | Result |
|---|---|
| `composer lint` | passed — PHPCS, Rector dry-run (0 changes), PHPStan level 9, kb-lint, docs links, suite coverage |
| `./vendor/bin/phpunit --testsuite unit` | passed — 1240 tests, 8790 assertions, ~9.5 s |
| `./vendor/bin/phpunit tests/Client/ProducerDocblockTest.php` | passed — 3 tests, 8 assertions |

`findings-coder.md` states 8794 assertions; measured 8790 (PHP-version variance,
nit only).

## 6. High-risk areas checked

- **Public API/BC:** no signature changed; only docblocks and annotation-only
  imports added (PHPCS `UnusedUses` has `searchAnnotations=true`; lint is clean).
- **Gate meaningfulness:** mutations A–E all fail as intended.
- **Doc↔code consistency:** exception sets traced to concrete throw sites;
  `sendWithFilter` v1/v2, `close`, `isClosed` and `createProducer` verified.
- **Publishing-id semantics:** the named-producer claim is correct; the
  anonymous-producer claim is wrong (the medium finding).

## Summary

- high: 0
- medium: 1 (anonymous publishing IDs documented 1-based vs 0-based code/tests)
- low: 4 (`waitForConfirms()` missing `ProtocolException`; `close()`
  over-declared `InvalidArgumentException`; `querySequence()` empty-name
  inconsistency; gate does not require any `@throws`)
- nit: 1 (assertion count in `findings-coder.md`)

**Verdict: CHANGES REQUESTED** — fix the medium anonymous-id documentation
discrepancy (and ideally the lows) before merge.
