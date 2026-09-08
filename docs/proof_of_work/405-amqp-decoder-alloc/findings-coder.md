# #405 — coder findings

## Obstacles

- `tests/Client/AmqpDecoderTest.php` destructures the `[$value, $pos]` tuple in
  ~40 places, and `AmqpDecoder::decodeValue()` is public API. Resolved by
  keeping the public tuple wrapper and introducing private
  `decodeValueInPlace()` (see `code-decision-1.md`). No test edits needed.
- `readDescribedTypeWithPosition()` (private, returned a 3-tuple) had no
  external callers; folded into `decodeMessage()` and `readDescribedType()`
  rather than porting the triple-tuple convention.

## Surprises

- None functional. `composer lint` (PHPCS + Rector dry-run + PHPStan level 9)
  and the full unit suite (1070 tests, 8190 assertions) passed on the first run
  after the rewrite.

## Bugs noticed (out of scope, each with suggested fix)

1. **Dead-public-API drift: `AmqpDecoder::decodeValue()` is only used by
   tests** — no production caller (`src/Client/Message.php`,
   `src/Client/OsirisChunkParser.php`, `src/Client/AmqpMessageDecoder.php`,
   `src/Client/Consumer.php` all go through `decodeMessage()` / fast paths).
   Once the deprecation window allows, the public tuple wrapper could be
   deprecated (`@deprecated` + trigger_error) and removed in a major.
2. **`readBinary8/32`, `readString8/32`, `readSymbol8/32` duplicate the same
   length-prefix read six times** (`src/Client/AmqpDecoder.php`, the
   variable-width readers). A single shared helper
   `readLengthPrefixed(string $data, int &$position, bool $wide, string $what):
   string` would remove ~60 lines and keep the #451 subtraction-form bounds
   check in exactly one place. Suggested as follow-up refactor.
3. **`decodeMessage()` concatenates multi-Data-section bodies with `.=`**
   (`src/Client/AmqpDecoder.php:189` region) — O(n²) for a message with many
   Data sections. Rare in practice (Data sections are usually singular);
   buffering into an array and `implode`-ing once would fix it if it ever
   matters.
4. **Micro: `readUint8()` is called for four different format codes
   (0x50/0x52/0x53 ubyte/smalluint/smallulong plus 0x56 path)** — correct as
   written, just noting the intentional aliasing is undocumented at the match
   arms; a one-line comment grouping them would help readers (they already
   carry per-arm type comments, so this is cosmetic).
