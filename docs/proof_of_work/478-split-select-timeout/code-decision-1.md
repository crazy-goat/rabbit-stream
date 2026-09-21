# Code Decision — Round 1 — Issue #478

## Approach taken

Extracted a single private instance method, `StreamConnection::splitSelectTimeout(float $seconds): array{int, int}`,
that turns a timeout in seconds into the `(tv_sec, tv_usec)` pair
`stream_select()`/`select(2)` expects:

```php
private function splitSelectTimeout(float $seconds): array
{
    $sec = (int) $seconds;
    $usec = (int) (($seconds - $sec) * 1_000_000);

    return [$sec, $usec];
}
```

The two halves are derived from the *same* value in one place, so the
fractional part is always in `[0, 1)` and the invariant `0 <= tv_usec < 1_000_000`
holds for every non-negative input. This is the property #382 violated at one
site (clamped seconds, unclamped microseconds → `tv_usec = 1_500_000` → EINVAL
on Linux / silent clamp on BSD).

## The cap stays at the call site

`readLoop()` deliberately waits at most one second per `stream_select()` so
`stop()` and the deadline check run regularly. That policy is unchanged and
stays where it belongs:

```php
[$selectTimeoutSec, $selectTimeoutUsec] = $this->splitSelectTimeout(min($remaining, 1));
```

`sendFrame()`/`readFrame()`/`writeAll()`/`readBytes()` pass their full remaining
budget through the same helper. The helper itself knows nothing about a cap, so
there is no way to clamp one half and leave the other unclamped — the exact
shape that caused #382.

## Scope: six call sites, not three

The issue names three sites (`sendFrame()`, `readLoop()`, `readFrame()`), which
were the three that assigned the split to named variables. The file has grown
since the issue was filed and now contains **six** occurrences of the same
arithmetic; three more were inlined directly into the `@stream_select()` call:

| # | Method | Before |
|---|--------|--------|
| 1 | `splitSelectTimeout()` (new) | — |
| 2 | `enableCrypto()` | `(int) $remaining`, `(int) (($remaining - (int) $remaining) * 1e6)` |
| 3 | `sendFrame()` | `$timeoutSec` / `$timeoutUsec` |
| 4 | `writeAll()` | inline expression |
| 5 | `readLoop()` | capped split (`min($remaining, 1)`) |
| 6 | `readFrame()` | `$timeoutSec` / `$timeoutUsec` |
| 7 | `readBytes()` | inline expression |

All six were routed through the helper. I verified each argument is
non-negative at the split, so the helper's documented pre-condition holds:

- `enableCrypto()`, `writeAll()` and `sendFrame()` throw/handle before the split
  when the remaining budget is `<= 0`;
- `readLoop()` checks `$remaining <= 0` and then caps with `min($remaining, 1)`;
- `readFrame()` guards `$timeout > 0` and uses `0/0` otherwise (preserving the
  original non-blocking-poll behaviour for `timeout <= 0`);
- `readBytes()` calls `readTimeout()` first, which either returns `null` (empty
  read at a frame boundary) or throws — so execution past that guard always has
  `$remainingTime > 0`.

Routing the extra three sites is what actually removes the duplication the
issue is about; leaving them inlined would have meant a helper used at half of
its occurrences and three copies of the EINVAL-prone arithmetic still free to
diverge.

## What I rejected

1. **Making the helper `private static`.** Rector's
   `LocallyCalledStaticMethodToNonStaticRector` (part of `composer lint`,
   dry-run) requires non-static when a method is only called via `self::` from
   instance methods. The helper is now `private function` and called with
   `$this->`.
2. **Keeping the helper cap-aware** (e.g. a `bool $capAtOneSecond` flag). The
   issue explicitly suggests keeping the cap at `readLoop()`; a boolean
   parameter would hide a policy decision inside the arithmetic helper and make
   it easy to pass the wrong flag. Clamping the argument at the one call site
   that wants it is clearer and leaves the helper a pure split.
3. **The `min($remaining, 1)` cap inside the helper** as the issue's raw snippet
   showed. Applying the cap to `sendFrame()`/`readFrame()` would shorten their
   waits (behaviour change) and is not needed for correctness — the invariant
   holds without any cap.
4. **Not touching `enableCrypto()`/`writeAll()`/`readBytes()`** because the issue
   listed three sites. See "Scope" above: the arithmetic is identical and the
   refactor's purpose is to make the bug class impossible at every site.

## Behaviour preservation

- Pure arithmetic extraction; no `socket_select`/`stream_select` argument value
  changes for any input.
- `readFrame(0.0)` and `readFrame(-x)` still pass `(0, 0)`.
- `readLoop()` still polls at most once per second.
- `enableCrypto`/`writeAll`/`readBytes` still pass the full remaining budget.

## Tests

- New unit test
  `tests/StreamConnectionTest.php::testSplitSelectTimeoutKeepsMicrosecondsBelowOneMillion`
  accesses the private helper by reflection (the pattern already used
  throughout this file) and asserts `sec >= 0` and `0 <= usec < 1_000_000` for
  `0.0`, `PHP_FLOAT_EPSILON`, `0.999999`, `1.0`, `2.5`, `30.0`, a seeded
  100,000-value sweep of whole seconds + pseudo-random fractions, and a
  boundary sweep of fractions a hair below `1.0`.
- The existing `testReadLoopHandlesTimeoutLongerThanOneSecond` regression test
  is unchanged and still passes; the E2E timing tests pass against a real
  broker.

## Uncertainties

- The invariant proof relies on `(int)` truncating a non-negative float to its
  floor and IEEE-754 rounding not pushing `fraction * 1e6` to exactly `1e6`.
  The boundary sweep (fractions `1.0 - k*PHP_FLOAT_EPSILON`) plus the 100k
  fuzz covers this; for realistic socket timeouts the closest reachable value
  is `999_999.999...`.
- Inputs beyond `PHP_INT_MAX` seconds are out of contract (a cast overflow,
  not a realistic socket timeout); the helper does not attempt to guard them.
