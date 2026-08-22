# Findings (coder) — AmqpDecoder element-count cap (issue #449)

**Issue:** #449
**Branch:** `feature/issue-449-amqp-decoder-count-cap`

Obstacles, surprises, and bugs noticed along the way — including out-of-scope
ones. Each has a file:line and a suggested fix.

---

## Biggest problem faced

The issue's headline PoC and its *primary* suggested fix do not match, and the
discrepancy only became clear after tracing the decode loop by hand.

**Primary suggested fix:** cap `$count` to `$endPosition - $position + 1` (the
bytes the compound's own `size` field says are present) and throw when
exceeded.

**Headline PoC:** an 8 MB frame with a *truthful* `count` of 8 M null elements
and 8 M content bytes → ~8 M-entry array → uncatchable OOM at
`memory_limit=128M`.

For that honest payload `count == available`, so `count > available` is
**false** and the suggested guard does **not** fire — the loop still runs to
completion and OOMs. I verified this by reading the loop:
`src/Client/AmqpDecoder.php:540-547` (`readList32`) and `:612-625`
(`readMap32`). Each `decodeValue()` of a `0x40` null advances `$position` by
exactly 1 (the format-code byte, `AmqpDecoder.php:36-38`), so with N content
bytes the loop happily appends N buckets; the in-loop `position > endPosition`
check (`:541`, `:613`) only trips *after* the content is exhausted, by which
point the array is already built.

**Resolution:** I implemented the available-bytes guard (it fully closes the
malformed-count-vs-size vector and upgrades its diagnostic), AND added a
per-compound element cap (`MAX_COMPOUND_ELEMENTS = 131072`) — the
"total-element budget" alternative the issue itself mentions — which closes the
honest-large-frame OOM. Both guards fire before the allocation loop. F1 below
is now **closed by this fix**; F2–F4 remain out of scope.

---

## F1 — Honest large-frame breadth amplification — CLOSED by this fix

- **Where:** `src/Client/AmqpDecoder.php:540` (`readList32` loop),
  `:612` (`readMap32` loop). Also `src/StreamConnection.php:54`
  (`DEFAULT_MAX_FRAME_SIZE = 8 MiB`) — the frame limit that makes an 8 M-element
  list reachable on the wire.
- **What:** A truthful `count` of N with N content bytes (all ≥1-byte elements
  such as `0x40` null) builds an N-element PHP array. At N ≈ 8 M (within the
  8 MiB frame limit) that is ~128 MB (packed) to ~256 MB+ (map buckets) of
  array storage → fatal `Allowed memory size exhausted` at `memory_limit=128M`.
  The available-bytes guard does not fire because `count == available`.
- **Status:** **Closed.** The `MAX_COMPOUND_ELEMENTS = 131072` cap
  (`AmqpDecoder.php:19`) rejects any compound declaring more than 128 K
  elements before the loop, with a catchable `DeserializationException`.
  Tests `testDecodeList32HonestLargeFrameThrowsBeforeAllocating` and
  `testDecodeMap32HonestLargeFrameThrowsBeforeAllocating` verify the headline
  PoC shape (truthful size, count = 131 073) throws before allocating.

## F2 — Pre-existing off-by-one in `$endPosition` for all four compound readers
**Pre-existing, not introduced here; affects the available-bytes guard's window.**

- **Where:** `src/Client/AmqpDecoder.php:493` (`readList8`:
  `$endPosition = $position + $size - 1`), `:522` (`readList32`:
  `$endPosition = $position + $size - 4`), `:560` (`readMap8`:
  `... $size - 1`), `:595` (`readMap32`: `... $size - 4`).
- **What:** `size` is the byte count of the *body* (count field + content). For
  list32 the content starts at `$position` after the 8-byte size+count header,
  so the last valid content byte is at `$position + ($size - 4) - 1 =
  $position + $size - 5`. The code sets `$endPosition = $position + $size - 4`,
  i.e. **one past** the real last content byte, and then treats it as an
  *inclusive* bound (`if ($position > $endPosition)`). Net effect: the window
  is 1 byte too large. The 8-bit readers have the same shape (`$size - 1`
  instead of `$size - 2`). This is harmless for correctness today (the next
  `decodeValue()` throws `Unexpected end of data` if it runs off the end) and
  harmless for the new `count > available` cap (one byte looser is still tight),
  but it is the reason the new exception says "available bytes 2" for a
  `size=5` list32 with 1 real content byte (`available = $endPosition -
  $position + 1 = size - 3`, not `size - 4`).
- **Suggested fix:** set `$endPosition = $position + $size - 5` (list32/map32)
  and `$endPosition = $position + $size - 2` (list8/map8), i.e. the index of
  the last real content byte, and keep the inclusive `position > endPosition`
  checks. Would change the exact `available` numbers in the new exception
  messages and boundary tests, so do it as its own commit. Out of scope for
  #449.

## F3 — `decodeMessage()` does not validate section size vs. bytes consumed
**Pre-existing, out of scope.**

- **Where:** `src/Client/AmqpDecoder.php:113-126` (the `while ($position <
  $dataLength)` loop and `readDescribedTypeWithPosition` call).
- **What:** Each section is a described type whose value is a sized compound
  (list8/list32/map…). `decodeMessage()` advances `$position` to wherever
  `decodeValue()` stops and loops. It never checks that a section's declared
  `size` matches the bytes actually consumed, so an inner `size` that
  *understates* the content lets the next section's descriptor be read from the
  middle of the previous section's bytes (mis-parse), and an *overstated*
  `size` can skip trailing bytes. Not a memory-safety issue by itself (the
  per-element guards still bound allocation), but a robustness gap. This is the
  same class of validation gap as issue #453.
- **Suggested fix:** after reading each described value, assert
  `$position == $expectedSectionEnd` where the end is derived from the value's
  own size, or at least that `$position <= $dataLength`. Out of scope for
  #449; tracked separately as #453.

## F4 — `readMap8`/`readMap32` integer-truncating division on odd `count`
**Pre-existing, minor.**

- **Where:** `src/Client/AmqpDecoder.php:563` (`readMap8`: `$numPairs =
  (int)($count / 2)`), `:610` (`readMap32`: same).
- **What:** AMQP map `count` is the total number of key+value entries and must
  be even. An odd `count` (malformed) is silently truncated to
  `floor(count/2)` pairs, dropping the trailing half-element silently rather
  than rejecting the frame. Harmless for memory, but a malformed frame is
  accepted as if well-formed.
- **Suggested fix:** `if ($count % 2 !== 0) throw new
  DeserializationException('Map count must be even, got ' . $count);` before
  computing `$numPairs`. Out of scope for #449.

---

## Notes on verification

- `composer install` was run in the worktree (it had no `vendor/`); the shared
  checkout's `vendor/` is on a different branch and must not be reused.
- Unit suite: 874 tests, OK. One **pre-existing** risky test,
  `tests/StreamConnectionTest.php:569
  ::testDispatchMetadataUpdateWithoutCallbackDoesNotCrash` (no assertions) —
  unrelated to this change, present before it (tracked as #454).
- `composer phpstan` (level 9): 0 errors. `composer cs` (PSR-12): clean.
  `composer lint` (PHPCS + Rector dry-run + PHPStan + kb-lint): clean, so the
  pre-push gate will pass.
