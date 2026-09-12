# Findings — issue #406

## Main discovery: the issue was already fixed on `main`

The performance work in #406 was delivered by an earlier PR. `git log -S "strrev"
-- src/Client/AmqpDecoder.php`:

- `d392ab3` (feat #51) — original decoder, used `strrev(substr(...))`
- `25b4507` (perf, PR #493, closes #492) — `AmqpDecoder: offset-form unpack(),
  big-endian formats instead of strrev()`

`grep -rn "strrev" src/ tests/` returns nothing. The `unpackInt` → `safeUnpack`
→ `is_scalar` chain from the issue is also gone (replaced by
`unpackIntAt()` / `unpackFloatAt()`). The issue is effectively a duplicate of
work already merged under #492/#493 and can be closed as resolved once the new
boundary tests land.

## Bugs / weak spots noticed (some outside this issue's scope)

### 1. `ulong` silently wraps above `PHP_INT_MAX` — `src/Client/AmqpDecoder.php:378-386`

`readUint64()` uses `unpack('J')`, which returns a native signed PHP int. For an
AMQP `ulong` (0x80) whose top bit is set (e.g. `0xFFFFFFFFFFFFFFFF`) it returns
`-1` instead of rejecting the value. `ReadBuffer::getUint64()`
(`src/Buffer/ReadBuffer.php:107-125`) was hardened in #393 specifically to throw
`DeserializationException` for this case, but `AmqpDecoder` was never updated,
so the two decoders disagree. Confirmed locally: decoding
`"\x80" . str_repeat("\xff", 8)` returns `int(-1)`.

Suggested fix: after `unpackIntAt('J', …)` in `readUint64()`, add
`if ($value < 0) { throw new DeserializationException('uint64 value 0x… exceeds PHP_INT_MAX'); }`
mirroring `ReadBuffer::getUint64()`, plus a test. Left out of this change to
preserve behavior/out of scope; flagging for a follow-up.

### 2. Repeated `Data` section concatenation is O(n²) — `src/Client/AmqpDecoder.php:216-217`

```php
$currentBody = $sections['body'];
$sections['body'] = (is_string($currentBody) ? $currentBody : '') . $value;
```

A message with many Data (0x75) sections copies the accumulated body on every
section. Suggest collecting into a local `$bodyParts = []` and `implode('', $bodyParts)`
once when the loop ends. Minor for typical single-section messages; only bites a
pathological many-section payload.

### 3. Dead `false` checks in `unpackIntAt()` / `unpackFloatAt()` — `src/Client/AmqpDecoder.php:289-310`

`unpack()` only returns `false` for an empty format string or a too-short input;
with the fixed literal formats passed here and the preceding bounds guards it
cannot happen, so the branches are effectively unreachable. Harmless
defensive code — noted only for completeness, not worth changing.

### 4. `readInt64()` comment is slightly misleading — `src/Client/AmqpDecoder.php:393-395`

The comment says "`'J'` unpacks as a native 64-bit PHP int, which is already
signed". More precisely: `'J'` is unsigned on the wire but PHP has no unsigned
64-bit type, so the bit pattern is reinterpreted as signed. The conclusion (no
manual correction) is correct; only the wording could mislead a future reader
into thinking `J` is a signed format.
