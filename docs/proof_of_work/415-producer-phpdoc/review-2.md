# Review — Round 2 (convergence) — Issue #415 (Producer public API PHPDoc and @throws)

**Reviewer:** review (deep, evidence-backed)
**Branch:** `feature/issue-415-producer-phpdoc`
**Base:** `main`
**Reviewed HEAD:** `e7004c1` (round-1 review was `a58de46`; diff vs `main`)
**Scope of this round:** verify every round-1 finding against the current tree,
run the local QA gates, and sweep `git diff main...HEAD` for new issues.
Read-only w.r.t. source; mutation checks were done in a throwaway copy outside
the repo.

## Verdict: **CONVERGED** — no open high, medium or low findings

All one medium, four low and the one nit from round 1 are resolved. The only
open item is a cosmetic assertion-count nit that varies by PHP version.

---

## 1. MEDIUM — anonymous publishing IDs are now 0-based ✅

Round-1 finding: class docblock + API-reference example + note claimed the
anonymous sequence was 1-based, contradicting the 0-based implementation, the
unit tests and the E2E tests.

Verified the corrected sources:

- `src/Client/Producer.php:688-691` — `getLastPublishingId()` docblock now says
  "An anonymous producer's first publish uses id 0, so this returns null until
  its first send() and 0 immediately after it."
- `docs/en/api-reference/producer.md:355-363` — example is now
  `// Anonymous producer`, `$id1 = … // 0`, `$id2 = … // 1`,
  `$id3 = … // 4 (1 + 3 messages)`.
- `docs/en/api-reference/producer.md:370-374` — "Publishing IDs start at 0 for
  unnamed producers (the first `send()` uses id `0` …)" and
  "start at `querySequence() + 1` for named producers".
- `CHANGELOG.md` #415 entry — states "Anonymous publishing ids are documented as
  **0-based**: the first `send()` uses id `0`, so `getLastPublishingId()` is
  `null` before it and `0` after."

Cross-checked against the pinned behaviour (unchanged from round 1):

- `tests/Client/ProducerTest.php:289` → `0`, `:292` → `1`.
- `tests/E2E/ProducerTest.php:86` → `0`, `:89` → `2`.

The class docblock, the API reference, the CHANGELOG and the tests are now
mutually consistent. Repo-wide grep found no remaining "start at 1" claim.

## 2. LOW — `waitForConfirms()` declares `ProtocolException` ✅

- Class: `src/Client/Producer.php:666-667` — `@throws ProtocolException If a
  server-push frame read while waiting has an unexpected version or command.`
- Interface: `src/Contract/ProducerInterface.php:79`.
- API reference: `docs/en/api-reference/producer.md:298`.
- CHANGELOG #415 entry mentions it.
- `EXPECTED_THROWS['waitForConfirms']` includes `ProtocolException`, so the gate
  pins it (see §4).

## 3. LOW — `close()` no longer over-declares `InvalidArgumentException` ✅

`close()` now declares only `ConnectionException`, `DeserializationException`,
`ProtocolException`, `TimeoutException` in the class (`Producer.php:528-536`),
the interface (`ProducerInterface.php:64-67`) and the API reference
(`producer.md:237-240`). The `InvalidArgumentException` tag is gone from all
three.

## 4. LOW — `querySequence()` empty-name consistency ✅

`src/Client/Producer.php:757` now rejects both `null` and `''`:

```php
if ($this->name === null || $this->name === '') {
    throw new InvalidArgumentException('Cannot query sequence for unnamed producer');
}
```

This matches `initializePublishingId()` (`:268`, `name !== null && name !== ''`)
and the constructor `@param` (`null (or "")` anonymous). The docblock text in
the class, interface and API reference now says "a `null` or `""` name", and the
new regression test `ProducerTest::testQuerySequenceThrowsForEmptyNameProducer`
pins it (verified: `--filter` runs both unnamed/empty-name cases, 2 tests,
4 assertions).

**Is rejecting `''` correct and consistent everywhere?** Yes, and it is
wire-safe:
- `initializePublishingId()` already treated `''` as anonymous, so the public
  method and construction agreed only after this change; before, a `name: ''`
  producer was anonymous locally but `querySequence()` sent a
  `QueryPublisherSequenceRequestV1` with an empty reference.
- `DeclarePublisherRequestV1::toStreamBuffer()` serializes the reference as
  `$this->publisherReference ?? ''` (`src/Request/DeclarePublisherRequestV1.php:38`),
  so `null` and `''` are the *same bytes* on the wire. There is no third
  semantic for `''`, so collapsing it into the anonymous branch is the only
  consistent choice.
- The only production behaviour change in the whole PR is this one condition; it
  is correctly recorded under `### Fixed` in the CHANGELOG and covered by a new
  unit test. Acceptable scope for a docs/`@throws` issue because round 1
  explicitly asked for it and it removes a documented↔code contradiction.

No other `name`-handling call site needs the same treatment: the remaining two
uses (`ensureDeclared()` `:228` and `declare()` `:337`) pass the name to
`DeclarePublisher`, where `''` and `null` are identical.

## 5. LOW — the reflection gate now pins the `@throws` sets ✅

`tests/Client/ProducerDocblockTest.php` gained
`testEveryThrowingPublicMethodDeclaresItsExpectedThrows` (`:211-247`) with a
curated `EXPECTED_THROWS` map (`:42-98`) comparing each public method's actual
`@throws` set to the expected set with `assertSame`, so a missing tag, an extra
tag, or a public method without an entry all fail.

Independently verified in a throwaway copy (rsynced repo + real `vendor/` copy
whose autoload resolves the copy's `src/`; confirmed via
`ReflectionClass(Producer::class)->getFileName()`):

| Mutation | Result |
|---|---|
| Remove `@throws ProtocolException` from `waitForConfirms()` | **FAIL** — `waitForConfirms` expected `[Connection, Deserialization, Protocol, Timeout]`, actual lacked `ProtocolException` |
| Remove `@throws InvalidArgumentException` from `send()` | **FAIL** |
| Add an unexpected `@throws \RuntimeException` to `isClosed()` | **FAIL** |

This closes the round-1 gap where stripping every `@throws` from `send()` left
the old gate green. The map is self-consistent with the tree (baseline run:
4 tests, 9 assertions, OK).

## 6. NIT — assertion counts

`./vendor/bin/phpunit --testsuite unit` measured **1242 tests, 8793
assertions**. `code-decision-2.md:82` records 8797 and `findings-coder.md:62`
records 8790. The test *count* matches exactly; the assertion count varies by
PHP version (this machine: PHP 8.5.10). Cosmetic only; no action required.

---

## New-issue sweep (`git diff main...HEAD`)

- **Scope:** the diff is docblocks (`Producer.php`, `ProducerInterface.php`),
  user-facing docs (`producer.md`, `publishing.md`,
  `named-producer-deduplication.md`), `CHANGELOG.md`, the new reflection gate,
  the new `ProducerTest` case, and the POW artifacts. The only non-doc source
  change is the single `querySequence()` condition covered in §4.
- **Docs↔code cross-checks:** `createProducer()` signature in `producer.md:34-49`
  matches `Connection::createProducer()` (`Connection.php:949-955`), including
  the `?float`/`int` parameter order and the `ProducerInterface` return type;
  `Producer::DEFAULT_MAX_PENDING_CONFIRMS` / `DEFAULT_REDECLARE_TIMEOUT` are
  public constants (`Producer.php:54-55`); the new `isClosed()` section and all
  new internal anchors pass the docs link checker.
- **`sendWithFilter()` v1/v2 claim** (`producer.md:214-216`) matches the code
  (null filter → v1; non-null without negotiated v2 → `ProtocolException`).
- **No new gaps:** `EXPECTED_THROWS` covers all 13 public methods including the
  accessors with an empty set; `testEveryPublicMethodIsDocumented` and
  `testEveryDocumentedThrowsNamesARealClass` are unchanged and still pass.
- **Historical plan docs** (`docs/plans/**`) still contain the old design's
  sentinel (`getLastPublishingId()` asserted `-1` before a send in the TDD
  sketch). These are dated design/planning artifacts that predate the branch and
  are outside the deliverable; not a finding.

## Local QA (HEAD `e7004c1`, clean tree)

| Command | Result |
|---|---|
| `composer lint` | passed — PHPCS PSR-12, Rector dry-run (0 changes), PHPStan level 9, kb-lint, docs links, suite coverage |
| `./vendor/bin/phpunit --testsuite unit` | passed — 1242 tests, 8793 assertions, ~9.5 s |
| `./vendor/bin/phpunit tests/Client/ProducerDocblockTest.php` | passed — 4 tests, 9 assertions |
| `./run-e2e.sh` (Docker available) | passed — 147 tests, 3059 assertions |
| Mutation checks (throwaway copy) | required `@throws` removals/additions fail the gate, as intended |

## Summary

- high: 0
- medium: 0 (round-1 medium fixed and re-verified)
- low: 0 (all four round-1 lows fixed and re-verified)
- nit: 1 (assertion counts in `code-decision-2.md`/`findings-coder.md`; PHP-version variance, no action)

**Verdict: CONVERGED — no open high/medium/low findings.**
