# Coder Findings — Issue #478

## Biggest problem during implementation

**Where:** the file state versus the issue text.

**Why hard:** the issue (and the task brief) describe **three** sites in
`src/StreamConnection.php`, at line numbers (`319`, `450`, `636`) that no longer
exist. The file has grown since #382/#478 were filed and the same
`(sec, usec)` split is now written out **six** times: the three the issue names
(named `$timeoutSec`/`$selectTimeoutSec` variables) plus three inlined directly
into the `@stream_select()` call in `enableCrypto()`, `writeAll()` and
`readBytes()`. Extracting a helper but routing only the named three would have
left half the duplication (and the EINVAL-prone arithmetic) in place.

**How solved:** routed all six through the one helper after verifying each
argument is non-negative at the split point. The dangerous one was `readBytes()`:
its call is guarded by `if ($remainingTime <= 0 && $this->readTimeout(...))`,
and `readTimeout()` never returns `false` (it returns `true` for an empty
frame-boundary read or throws), so execution past the guard always has
`$remainingTime > 0`. Documented in `code-decision-1.md`.

Also kept the `readLoop()` one-second poll cap at the **call site**
(`$this->splitSelectTimeout(min($remaining, 1))`), exactly as the issue
prescribes, so the policy stays visible and the helper stays a pure split.

## Obstacles & surprises

- Rector (part of `composer lint`, dry-run) rejected `private static function
  splitSelectTimeout()` because it is only called via `self::` from instance
  methods (`LocallyCalledStaticMethodToNonStaticRector`). Converted to a private
  instance method; the reflection test now invokes it on an instance.
- The reflection test's `ReflectionMethod::invoke()` returns `mixed`, and there
  is no `phpstan/phpstan-phpunit` extension in this project, so `assertIsArray()`
  would not narrow for PHPStan level 9. Used an explicit
  `@var array{int, int}` annotation instead.
- The new test reports `500480 assertions` for ~100k values because each
  `assertGreaterThanOrEqual`/`assertLessThan` counts as multiple internal
  assertions; runtime is only ~2.7 s.

## Discovered bugs / places to improve (outside this issue's scope)

1. **Issue #478's line numbers are stale** (`319`/`450`/`636`; the sites are now
   at `~925`/`~1230`/`~1500`). No action beyond this note; the acceptance
   criteria are about the arithmetic, not the line numbers.
2. **`enableCrypto()` / `writeAll()` / `readBytes()` were the three extra
   split sites.** Now covered by the helper, so no follow-up.
3. **Pre-existing, still open:** `readFrame()`'s single `stream_select` can
   block for the full remaining timeout (30 s default) in one call, so `stop()`
   cannot interrupt `readMessage()` mid-select (noted as item 4 in the #382
   `findings-coder.md`). Unchanged by this pure refactor; if responsiveness is
   ever required there, `readFrame()` should poll in ≤ 1 s chunks like
   `readLoop()` — the helper now makes that a one-line clamp at the call site.

No new bugs were introduced. The only behaviour-adjacent edits are mechanical:
`readFrame()` keeps its `timeout <= 0 → (0, 0)` non-blocking-poll branch
explicitly; all other sites keep their exact arguments.

## Tests / lint

- `./vendor/bin/phpunit --testsuite unit` — **OK (1226 tests, 509226
  assertions)**.
- New `testSplitSelectTimeoutKeepsMicrosecondsBelowOneMillion` — boundary cases
  plus a seeded 100,000-value sweep; asserts `sec >= 0` and
  `0 <= usec < 1_000_000`.
- Existing `testReadLoopHandlesTimeoutLongerThanOneSecond` regression test
  (unchanged) still passes.
- `composer lint` — **OK** (PHPCS, Rector dry-run, PHPStan level 9, kb-lint,
  docs links).
- `./run-e2e.sh` — **OK (147 tests, 3059 assertions)** against a real RabbitMQ
  broker (Docker), including the connection-timing tests.

## Follow-up candidates for the retro (propose only)

- Candidate KB entry: "A helper that decomposes a duration into
  `(sec, usec)` must own the *whole* decomposition; clamp the input at the call
  site, never one half." Tags `socket`, `select`, `timeout`, `refactor`.
  Trigger: "when splitting a timeout for `select`/`stream_select`".
- The cross-platform invariant test is the durable gate; the KB entry can be
  `promoted` to it.
