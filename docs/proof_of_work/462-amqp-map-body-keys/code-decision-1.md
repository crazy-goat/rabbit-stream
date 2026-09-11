# Code decision 1 — preserve map keys in AmqpValue bodies (issue #462)

**Issue:** #462
**Branch:** `feature/issue-462-amqp-map-body-keys`
**Round:** 1

## Problem

`Message::applyDecodedSections()` ran `array_values()` over any array-valued
body. The issue was filed against `src/Client/AmqpMessageDecoder.php:17-19`,
but the code has since moved: the call now lives at
`src/Client/Message.php:227` (the decoder itself became lazy in the meantime).

`AmqpDecoder` builds AMQP maps (`readMap8` / `readMap32`,
`src/Client/AmqpDecoder.php:720,814`) as `array<int|string, mixed>` and lists
(`readList8` / `readList32` / `readArray8` / `readArray32`) as sequential
`list<mixed>`. `array_values()` is a no-op on the lists and destructive on the
maps — so `AmqpValue` body `{"a":1,"b":2}` reached consumers as `[1,2]`, with
no exception and no warning. Silent data corruption.

Reproduced before the fix (inline fixture, AmqpValue `0x77` + map8):
`getBody()` returned `array(2) { [0]=> 1, [1]=> 2 }`.

## Approach taken

One-line behavioural change in `Message::applyDecodedSections()`
(`src/Client/Message.php:225`): stop re-indexing. The decoded body is now kept
verbatim whenever it is an array, null or scalar:

```php
if (is_array($rawBody) || $rawBody === null || is_scalar($rawBody)) {
    // Keep the decoded value as-is: an AmqpValue (0x77) body may be a map
    // (array<int|string, mixed>) and re-indexing it with array_values()
    // would silently drop its keys (#462). Lists are already sequential.
    $body = $rawBody;
} else {
    $body = null;
}
```

The `else` branch (objects/unsupported types) is retained only as a
defensive guard — the decoder cannot currently produce one.

PHPDoc types widened, since `getBody()` / the constructor body may now be a
map rather than a list:

- `Message::__construct()` `@param`: `array<int, mixed>` → `array<int|string, mixed>`
- `Message::getBody()` `@return`: `array<int, mixed>` → `array<int|string, mixed>`

The native property type `string|int|float|bool|array|null` already admits
both shapes and was left unchanged.

## What I rejected and why

1. **`array_is_list($rawBody) ? array_values($rawBody) : $rawBody`** (the
   shape suggested in the issue). Rejected: `array_values()` on an array that
   is already a list is a no-op, so the branch adds a call and a condition for
   literally zero behavioural difference. The decoder already guarantees lists
   come out sequential (`$list[] = ...`), so "keep as-is" is sufficient and
   smaller.

2. **Exposing the raw decoded value / a separate `getRawBody()`** and letting
   the caller decide. Rejected as a public-API change far larger than the bug:
   `getBody()` has an established documented contract and every existing
   caller expects a decoded value. The issue's own preferred fix is to keep
   associative arrays, which this does.

3. **Preserving keys by re-keying with `array_combine`** or a manual loop.
   Rejected: pointless work when the array already carries the right keys.

4. **Touching `AmqpDecoder`.** Rejected: it already produces correct maps.
   The bug was purely the post-processing in `Message`.

## Tests

Added to `tests/Client/AmqpMessageDecoderTest.php` (the decoder-lazy path that
`Message::getBody()` actually goes through):

- `testDecodeAmqpValueWithMapBodyPreservesKeys` — AmqpValue `0x77` carrying
  map8 `{"a": 1, "b": 2}`, asserts `getBody() === ['a' => 1, 'b' => 2]`.
- `testDecodeAmqpValueWithListBodyReturnsSequentialList` — AmqpValue carrying
  list8 `[1, 2, 3]`, guards that the pre-existing list behaviour is unchanged.

The file had no AmqpValue helper (its two existing AmqpValue tests inline the
section bytes). I added a small `buildAmqpValueSection(string $valueData)`
helper mirroring the sibling `AmqpDecoderMessageTest::buildAmqpValueSection()`
and the file's existing `build*Section()` family, then inlined the map8/list8
payloads with a byte-layout comment. Pulling a full map/list builder into this
file would have been more helper code than the production fix.

## Uncertainties

- **PHP array key coercion.** AMQP map keys that are integers or
  integer-looking strings become PHP `int` keys (`{"1": ...}` → `[1 => ...]`),
  and a null/compound key collapses to `''` in `readMap*`. These are
  pre-existing `AmqpDecoder` behaviours; the fix faithfully preserves whatever
  the decoder produced. I did not try to "fix" key typing here — see
  `findings-coder.md`.
- **E2E not run.** This is not a wire-format change (the bytes on the wire are
  untouched; only the in-memory post-processing of an already-decoded section
  changes), so per the task `./run-e2e.sh` was skipped. The unit suite and the
  new tests cover the path.
