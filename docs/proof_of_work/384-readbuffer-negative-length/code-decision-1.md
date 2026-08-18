# Code decision 1 — ReadBuffer negative-length hardening (issue #384)

**Issue:** #384
**Branch:** `feature/issue-384-readbuffer-negative-length`

## What was asked

`ReadBuffer` (pre-auth reachable) accepted negative lengths other than the
`-1` null sentinel in `getString()`/`getBytes()` and unbounded `uint32`
array counts in `getStringArray()`/`getObjectArray()`. Combined, a
crafted frame (`count = 0xFFFFFFFF`, string length `0xFFFE`) made every
loop iteration consume zero net bytes (position moved **backward**) while
appending a copy of the frame body — remote OOM against a client talking
to a malicious broker/MITM, before authentication completes
(`SaslHandshakeResponseV1::getStringArray()`,
`PeerPropertiesResponseV1::getObjectArray(KeyValue::class)`).

Verified the bug by execution before touching code: buffer
`FF FE 41 42 43 44 45 46` → `getString()` returned `"ABCD"` (a suffix of
the buffer, `substr` with negative length) and left `position = 0`.

## Approach taken

Four surgical changes to `src/Buffer/ReadBuffer.php`, exactly per the
task spec:

1. **`getString()`** — after the `=== -1` null check, `$len < 0` throws
   `DeserializationException` (message includes the bad length and the
   position of the length field: `$this->position - 2`). The existing
   `ensureAvailable($len)` stays untouched after it.
2. **`getBytes()`** — identical pattern with `$size < 0` (position of the
   field: `$this->position - 4`).
3. **`getStringArray()`** — after `getUint32()`, computes
   `$remaining = strlen($this->buffer) - $this->position` and rejects the
   array **before** the loop when `$arrayLength * 2 > $remaining`
   (every string element costs ≥ 2 bytes: the int16 length prefix; a
   `null` element is also exactly 2 bytes). Message: `Invalid string
   array count %d at position %d: need at least %d bytes, but only %d
   available`.
4. **`getObjectArray()`** — same guard with `$arrayLength > $remaining`
   (every element costs ≥ 1 byte via `fromStreamBuffer`). Message:
   `Invalid object array count …`.

`getInt16()`/`getInt32()`/the `-1` sentinel are unchanged — `null` still
round-trips (`testGetStringNull`, `testGetBytesNull` still green).

### Why `*2` for strings and `*1` for objects

The bound must be a **necessary condition** — it may only reject input
that cannot possibly be valid, never input that could be. A string
element is always `int16 prefix (2 bytes) + payload (≥ 0 bytes)`, so 2
bytes is the tightest correct per-element minimum; `*2` rejects only
impossible arrays. An object element goes through a generic
`FromStreamBufferInterface::fromStreamBuffer` — the method cannot know
the concrete class's minimum consumption (the only caller today,
`KeyValue`, consumes ≥ 4 bytes, but the method is generic), so 1 byte is
the only safe lower bound; `*2` would wrongly reject a (hypothetical)
1-byte-consuming element class. Asymmetry is correct, not an oversight.

### Why the issue's "assert position strictly advances per iteration" was NOT added

The issue's fix list has a third bullet (strict-advance assertion) that
the task's fix list deliberately omits, and the two added guards make it
redundant: with `$len >= 0` enforced, every `getString()` consumes
≥ 2 bytes; with the pre-loop bound `count ≤ remaining / 2`, `getStringArray`
loop work is bounded by half the buffer size and position strictly
advances. For `getObjectArray`, the bound `count ≤ remaining` keeps the
iteration count bounded by buffer size even in the pathological
zero-consumption case (termination, no unbounded amplification) — a
per-iteration assertion would add a control-flow dependency on the
concrete class's consumption and was rejected as scope creep.

## What was rejected and why

- **Making `getInt16()`/`getInt32()` unsigned.** Rejected — these are
  general accessors whose signedness other code relies on
  (`WriteBuffer::addInt16`/`addInt32` mirror them); the `-1` sentinel is
  protocol-mandated (`int16`/`int32` length `-1` = null per the
  PROTOCOL.adoc frame layout, and AGENTS.md documents "-1 = null" for
  bytes). The validation belongs at the *call site that interprets the
  length*, i.e. `getString()`/`getBytes()`.
- **Bounding the array count inside the loop / checking after the first
  iteration.** Rejected — the task requires the check *before* the loop;
  a post-hoc check would still let the first iteration run (and, pre-fix,
  the loop never terminates so no later check would ever fire).
- **Tightening `getObjectArray` per-element cost to KeyValue's real 4
  bytes.** Rejected — see the `*1` rationale above; the method is generic.
- **Rewriting `getString()` to read a `uint16` and re-derive sign.**
  Rejected — duplicates existing logic, touches the sentinel path.
- **Adding the count bound inside `ensureAvailable`.** Rejected — the
  current underflow message shape ("Buffer underflow: need N bytes…") is
  used and asserted by existing tests; a pre-loop count rejection is a
  *semantic* error (impossible array), not an underflow.

## What I was unsure about

- **Position semantics in the error message.** `ensureAvailable` reports
  the position where the next read would happen; for the negative-length
  errors I report the position of the offending length field itself
  (`position - 2` / `position - 4`), because that is where the corrupt
  bytes sit in a hex dump. The two conventions differ slightly — noted,
  deliberate.
- **Message wording hit the 120-char line limit** (PSR-12 base in this
  repo includes `Generic.Files.LineLength` at warning severity, and
  warnings fail `composer cs`). First wording ("…each element needs at
  least 2 bytes, but only %d available") was 126–127 chars; reworded to
  the `ensureAvailable`-style "need at least %d bytes, but only %d
  available" phrasing that fits. Gate not lowered.
- **Generalizing the `*2` bound to `*1` for objects** — see above; the
  generic-method argument settled it.
- The pre-existing "risky" unit test (`tests/StreamConnectionTest.php:567`,
  a test performing no assertions) is unrelated to this change — it
  reports `Risky: 1` on `main` too; left untouched.

## Tests added (tests/Buffer/ReadBufferTest.php, 5 new)

- `testGetStringWithNegativeLengthThrows` — `0xFFFE`, asserts
  `DeserializationException` with `Invalid string length -2` + `position 0`.
- `testGetBytesWithNegativeLengthThrows` — `0xFFFFFFFE` (-2 as int32),
  asserts `Invalid bytes length -2` + `position 0`.
- `testGetStringArrayWithHugeCountAndNegativeLengthThrows` — the OOM
  attack vector: count `0xFFFFFFFF` + string length `0xFFFE`; asserts the
  pre-loop guard throws `DeserializationException` (the test would
  previously have OOM'd — this is the regression guard).
- `testGetStringArrayWithCountLargerThanRemainingThrows` — count 1000
  with 5 bytes left.
- `testGetObjectArrayWithCountLargerThanRemainingThrows` — count 1000
  with 11 bytes left.

Existing `-1` → `null` coverage (`testGetStringNull`,
`testGetBytesNull`) already existed; not duplicated.

## Evidence

- Bug reproduction (pre-fix): position `0 → 0`, returned 4-byte suffix.
- Post-fix full gate: `composer lint` (PHPCS + Rector dry-run + PHPStan
  level 9 + kb-lint) green; `./vendor/bin/phpunit --testsuite unit`
  green, 640 tests, only the pre-existing `Risky: 1` in
  `StreamConnectionTest`.
