# Findings — coder — issue #384

## Obstacles / surprises during implementation

1. **The bug reproduced even more dramatically than the issue described.**
   `substr($buf, 0, -2)` with `0xFFFE` on an 8-byte buffer returned the
   whole buffer minus the last 2 bytes (`"ABCD"`) and left `position = 0`
   — zero net consumption, exactly the amplification loop. Confirmed
   before editing, which made the fix's shape unambiguous.

2. **PHPCS line-length warnings on the new messages.** This repo's
   PSR-12 base includes `Generic.Files.LineLength` (warning at 120 chars)
   and `composer cs` fails on warnings. My first message wording
   ("…each element needs at least 2 bytes, but only %d available") was
   126–127 chars and failed the gate; reworded to a shorter
   `ensureAvailable`-style phrasing ("need at least %d bytes, but only %d
   available"). Lesson: when adding multi-argument `sprintf` messages to
   this codebase, keep the format line ≤ ~100 chars at the usual nesting
   depth.

3. **Generic `fromStreamBuffer` bound choice (`*1` not `*2`).** The
   tempting symmetry ("strings cost 2, KeyValue costs 4, maybe objects
   cost 2") is wrong for a generic method: `getObjectArray` accepts any
   `class-string<FromStreamBufferInterface>`, so only 1 byte per element
   is a provably safe lower bound. Cost me a re-read of the
   `@template`/`@param class-string<T>` signature to settle.

## Discovered bugs / places to improve (including outside scope)

### 1. `ReadBuffer::skip()` accepts negative byte counts — same backward-position class as #384

- **Where:** `src/Buffer/ReadBuffer.php:242-246`
- **What:** `skip(int $bytes)` calls `ensureAvailable($bytes)` (which a
  negative value always passes, `-N < available`) and then
  `$this->position += $bytes` — moving the position **backward**, exactly
  the #384 defect shape. Not reachable with attacker data today: the only
  caller is `src/Response/DeliverResponseV1.php:56` with the constant
  `skip(8)`.
- **Suggested fix (hardening, low priority):** reject `$bytes < 0` with a
  `DeserializationException` at the top of `skip()`, mirroring the new
  `getString()`/`getBytes()` guards. Cheap defense-in-depth for a method
  whose contract says "bytes".

### 2. `ReadBuffer::readBytes()` accepts negative lengths — same class

- **Where:** `src/Buffer/ReadBuffer.php:248-254`
- **What:** `readBytes(int $length)` with a negative `$length` passes
  `ensureAvailable`, returns a near-full-buffer suffix via
  `substr($buffer, $position, -N)`, and moves `position` backward. Same
  amplification loop if a future caller ever passes a signed,
  attacker-derived value. Current callers are safe: the chunk parser
  (`src/Client/OsirisChunkParser.php:73,84,100,105`) uses only constants
  or unsigned values (`getUint32()`, `$header & 0x7FFFFFFF`), and
  `StreamConnection::readBytes()` is an unrelated private socket reader.
- **Suggested fix:** `if ($length < 0) throw new DeserializationException(...)`
  in `readBytes()`, same message style. Would make the whole class
  negative-proof in one more line each for `skip()` and `readBytes()`.
  Could be folded into a follow-up issue with finding 1.

### 3. `getObjectArray()` loop is bounded but still O(remaining) in the pathological zero-consumption case

- **Where:** `src/Buffer/ReadBuffer.php:149-167`
- **What:** after this fix the pre-loop guard guarantees termination
  (count ≤ remaining bytes), but if a future
  `FromStreamBufferInterface` implementation consumed 0 bytes per call,
  the loop would still run up to `remaining` iterations (bounded — no
  OOM amplification, just wasted CPU for a hostile frame). `KeyValue`
  (the only current element class) consumes ≥ 4 bytes, so this is
  theoretical today.
- **Suggested fix:** none required for #384; optionally document the
  "elements must consume ≥ 1 byte" contract on `getObjectArray()`'s
  docblock, or assert position advance per iteration if new element
  classes are ever added.

### 4. Pre-existing "risky" unit test (no assertions)

- **Where:** `tests/StreamConnectionTest.php:567`
- **What:** reports `Risky: 1` in the unit suite ("This test did not
  perform any assertions"). Pre-existing on `main`, unrelated to this
  branch.
- **Suggested fix:** add an assertion (e.g. on the dispatched frame) or
  mark the test with `@doesNotPerformAssertions` if the intent was a
  smoke test — but out of scope here; flagged for the record.

### 5. Minor: `getInt64()`/`getUint64()` platform notes (not bugs on supported targets)

- **Where:** `src/Buffer/ReadBuffer.php:80-108`
- **What:** `getUint64()` unpacking `'J'` returns a raw `int`, which
  wraps negative for values ≥ 2^63 on 64-bit PHP (the existing
  `testGetUint64WithMaxValue` documents the `-1` wrap). On a hypothetical
  32-bit PHP the `unpack('J')` result is a float despite the `int`
  return type. Neither affects this fix (no length is read as uint64)
  and the project targets 64-bit, so no action — recorded only.

## Candidate KB entry proposal (for the main session to decide)

- **Title:** `ReadBuffer: negative lengths move position backward — validate before ensureAvailable/substr`
- **Tags:** `buffer, security, protocol`
- **Trigger:** when adding or reviewing any `ReadBuffer` method that
  interprets a signed integer (getInt16/getInt32) as a length, or any
  method taking an `int` byte count (`skip`, `readBytes`)
- **One paragraph:** #384 was a remote-OOM class: signed lengths other
  than the `-1` sentinel passed `ensureAvailable`, made `substr` return a
  suffix, and moved `position` backward — zero net consumption per call
  so loop guards never fire. The rule now enforced in `getString` /
  `getBytes` / `getStringArray` / `getObjectArray`: non-sentinel negative
  lengths throw `DeserializationException`, array counts are bounded by
  remaining bytes before the loop (2 bytes per string element, 1 per
  object). `skip()` and `readBytes()` still accept negative counts —
  hardening them the same way closes the class for the whole class.
