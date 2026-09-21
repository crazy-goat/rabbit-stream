# Review 1 — #522 dropped-confirm observability (REVIEW-CRITICAL)

- Branch: `feature/issue-522-dropped-confirm-observability`
- HEAD: `b796894` (`feat(producer): observe dropped publish confirms on close drain timeout and unregistered ids (closes #522)`)
- Diff: `git diff main...HEAD` — 11 files, +381/-15
- Touches dispatch core: `src/StreamConnection.php` (`handlePublishConfirm`/`handlePublishError`), plus `src/Client/Producer.php`, `src/Client/Connection.php`, tests, docs.

## Verdict

**No high-severity findings.** One medium (unbounded publishing-id lists in warning context) and two
low/documentation items. Dispatch semantics are unchanged and confirmed by code inspection and tests.
Quality gates are green (see below).

## QA gates (all green)

| Gate | Result |
|------|--------|
| `./vendor/bin/phpcs --standard=phpcs.xml.dist` | OK (279 files, 0 errors) |
| `./vendor/bin/phpstan analyse` (level 9) | OK — No errors |
| `./vendor/bin/rector process --dry-run` | OK |
| `./vendor/bin/phpunit --testsuite unit` | OK — 1222 tests, 8734 assertions |
| `./run-e2e.sh` (Docker available) | OK — 147 tests, 3059 assertions |
| `php bin/kb-lint.php` | OK — 12 entries, 0 warnings |
| `php bin/check-docs-links.php` | OK — all relative links resolve |
| Targeted new/changed tests ×3 | OK (5 tests, 26 assertions, ~2.1 s each) |

## Check results

### 1. Do the logging changes alter dispatch semantics? — No (PASS)

`src/StreamConnection.php:1280-1298` (`handlePublishConfirm`) and `:1300-1326` (`handlePublishError`):
the registered path calls the callback and now `return`s. Nothing followed the `if` block before, so
the added `return` is a no-op for the registered path. The unregistered path still does not dispatch;
it only adds a `warning` log after the guard. The tombstone guard is intact.

`readLoop()` (`src/StreamConnection.php:1215-1240`) increments `$dispatched` for every frame routed
through `dispatchServerPush()` regardless of whether a callback fired, so a dropped frame still counts
as dispatched. The new tests assert `assertSame(1, $dispatched, ...)` for the unregistered cases, and
`assertSame([], $logger->warningMessages())` for the registered cases — a good regression guard in both
directions.

`src/Client/Connection.php:989` passes `logger: $this->logger`; no dispatch behaviour changed there.

### 2. Warning context / log-blowup risk — REAL, Medium (see findings-review.md F1)

Both new `StreamConnection` warnings put the **entire** `getPublishingIds()` list into the PSR-3
context (`StreamConnection.php:1295`, `:1315-1323`). With an 8 MB default max frame
(`DEFAULT_MAX_FRAME_SIZE`) and 8-byte ids, a single late `PublishConfirm` can carry ~1,000,000 ids.
`Producer::drainPendingConfirms()` likewise embeds every stranded id (`Producer.php:496`), and with
`maxPendingConfirms: 0` the pending set is unbounded. The issue asked only for "publisher id and frame
type"; the id lists are extra and uncapped. Low frequency (late/tombstone frames only) but potentially
large. `publisherId` and `frame` are correct for both paths.

### 3. `Producer` logger parameter — BC-safe (PASS)

`Producer::__construct` (`Producer.php:96-116`) adds `?LoggerInterface $logger = null` **after**
`?callable $onClose = null`. It is trailing + optional, so all positional call sites are unchanged;
the only named-argument use is the new test (`ProducerTest.php:1207`). Default is `NullLogger`, and
`Connection::newProducer()` passes `$this->logger` (`Connection.php:989`). All 46 `new Producer(...)`
call sites in `src/`, `tests/` and examples compile (verified by PHPStan level 9 + the unit suite).
`Connection::createProducer()` was intentionally not changed — it uses the connection logger.

### 4. `getLostConfirmCount()` semantics — correct and documented (PASS)

Cumulative over the producer's lifetime; incremented only in `drainPendingConfirms()`
(`Producer.php:482`), never in `waitForConfirms()`. `close()` is guarded by `$this->closed`, so the
drain runs at most once and the counter cannot double-count. Deliberately **not** on
`ProducerInterface` — consistent with the existing `isStale()` / `getRedeclareCount()` producer-only
introspection methods, and avoids a BC break for external implementors (the same reasoning #381 had to
flag). Documented in the Producer API reference with the exact "stranded ids remain visible through
`getPendingConfirms()`" caveat.

### 5. Drain-timeout test — deterministic, not flaky (PASS)

`testCloseGivesUpAfterDrainTimeoutWhenBrokerNeverConfirms` (`ProducerTest.php:1184-1226`) mocks
`readLoop()` to always return `0` and never invoke a confirm callback, so `drainUntilZero()` can only
exit via its deadline. `getPendingConfirms()` stays `1`, the loss is counted, and exactly one warning
is emitted. Re-ran 3× — identical result. The `assertLessThan(5.0, $elapsed)` bound is loose but safe.
Caveat: the mocked `readLoop()` returns instantly, so `drainUntilZero()` busy-spins for the full 2 s
wall clock; the test is ~2 s by construction. This is a test-cost/style note (the 2 s timeout is a
private const and cannot be injected), not a flake.

### 6. Stranded ids never retired from `pendingConfirms` — low / out of scope (see F2)

Pre-existing #474 behaviour, explicitly preserved and now documented. Not introduced by #522 and
deliberately not changed to avoid breaking the #474 test. Impact is limited because `close()` is
terminal and the producer is normally discarded.

### 7. `RecordingLogger` `array_values()` — correct (PASS)

New private `messagesAtLevel()` uses `array_values(array_map(array_filter(...)))`, so `warningMessages()`
and `debugMessages()` are 0-indexed even when other levels are interleaved (which happens with
`debugLogging = !$logger instanceof NullLogger`). All existing call sites index `[0]`, and the change is
a strict robustness improvement. No test depends on the previously preserved keys.

## Non-findings considered and dismissed

- `getLostConfirmCount()` absent from `ProducerInterface`: intended, matches existing producer-only
  methods.
- Warning level for a routine tombstone race: intentional (debug is swallowed by `NullLogger`); noisy
  only at close, bounded per frame, level-filterable.
- Warning also fires for never-declared ids: same guard, documented, correct.
- Docs example calls `getLostConfirmCount()` on the `ProducerInterface` returned by
  `createProducer()`: pre-existing pattern (same as `isStale()`), docs are class-scoped. Not a #522
  regression.

## Recommendation

Approve with the Medium F1 addressed (cap the id list in the warning context to a count + first N ids
in all three log sites) or explicitly deferred with a documented rationale. F2/F3 are optional.
