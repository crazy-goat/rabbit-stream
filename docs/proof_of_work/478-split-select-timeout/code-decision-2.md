# Code Decision — Round 2 — Issue #478

Round 1 was approved with three lows and one nit. This round addresses all
four without touching production behaviour (except making one precondition
explicit).

## Low 1 — static gate against a seventh inline split

**Decision: add a source-scanning unit test inside `StreamConnectionTest.php`.**

`testOnlySplitSelectTimeoutConvertsSecondsToMicroseconds()`:

1. Reads `src/StreamConnection.php` directly.
2. Blanks comments with `token_get_all()`, re-emitting one `\n` per newline in
   each `T_COMMENT`/`T_DOC_COMMENT` so reported line numbers still match the
   file on disk. This keeps the helper's own docblock (`tv_usec < 1_000_000`)
   from tripping the gate.
3. Gets `splitSelectTimeout()`'s line range from reflection.
4. Scans every remaining line with
   `~[*/]\s*(?:1_000_000|1000000|1e6)(?![0-9_])~i` and fails on any match
   outside the helper's range.

Why this shape:

- **Tolerant of formatting.** Accepts `1_000_000`, `1000000`, `1e6`/`1E6`, and
  arbitrary whitespace between the operator and the literal.
- **Not over-broad.** Requiring a `*` or `/` immediately before the literal
  excludes prose, `0x100000000`-style masks, and unrelated microsecond code
  (e.g. `Producer.php`'s `usleep($backoff * 1_000_000)` is not scanned because
  the gate is scoped to `StreamConnection.php`, where the `stream_select()`
  call sites live).
- **Not a no-op.** A positive control asserts the helper itself matches, so a
  regex regression cannot silently disable the gate.

Verified negatively: injecting `$foo = (int) (1.5 * 1_000_000);` at
`src/StreamConnection.php:933` made the test fail and name that line; the
injection was then reverted.

I kept the gate in `StreamConnectionTest.php` rather than a new architecture
test file: the property is specific to this class, and the file already uses
reflection to reach private members.

## Low 2 — `readBytes()` implicit non-negative precondition

**Decision: clamp at the call site with `max(0.0, $remainingTime)` and
document why.**

`readTimeout()` returns `true` only for an empty frame-boundary read and
otherwise closes the connection and throws, so it never returns `false`. The
only path that reaches the split with a non-positive budget is therefore
unreachable. Adding `max(0.0, ...)` makes the helper's precondition a local
fact instead of a cross-method invariant, and it cannot change any reachable
result: for every reachable path `$remainingTime > 0`, so `max()` is the
identity. The adjacent comment explains the coupling so a future change to
`readTimeout()` cannot quietly invalidate it.

Rejected: rewriting the guard's short-circuit order. The current order is
correct and clearer; the clamp is a smaller, more direct expression of the
precondition.

## Low 3 — assertion volume

**Decision: aggregate violations and trim the sweep to 5,000 values.**

- One `assertSame([], $violations, ...)` replaces up to 500,480 per-iteration
  assertions; the failure message lists the first 20 offending inputs.
- A single `assertSame(5096, $checked)` keeps the test from passing vacuously
  if the loops are ever emptied.
- The six explicit edge cases (`0.0`, `PHP_FLOAT_EPSILON`, `0.999999`, `1.0`,
  `2.5`, `30.0`) are unchanged; the 90-value near-1.0 boundary sweep is
  unchanged. The random sweep drops 100,000 → 5,000, which still covers all
  integer parts 0..999 five times over and every boundary case.
- Result: 500,480 assertions / ~2.7 s → 4 assertions / ~0.02 s, with the same
  discriminating power (the #382 shape still fails on the explicit `2.5`/
  `30.0` cases and the integer parts > 1).

## Nit — global MT seeding

**Decision: drop `mt_srand()`/`mt_rand()`; derive fractions from `crc32()`.**

`crc32((string) $i) / 4_294_967_296.0` is deterministic and reproducible
without mutating process-global state, so test order no longer matters. A local
`\Random\Randomizer` would have been idiomatic but requires PHP 8.2 while
`composer.json` still supports `>=8.1`; `crc32()` is portable and dependency
free.

## Behaviour-preservation check

- `src/StreamConnection.php` production change is limited to
  `max(0.0, $remainingTime)` on a provably non-negative reachable path.
- Test-only changes elsewhere.
- `readLoop()`'s one-second cap and `readFrame()`'s `<= 0 → (0,0)` branch are
  untouched.
