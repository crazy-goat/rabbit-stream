# Findings — coder — issue #462

## Obstacles / surprises during implementation

1. **The issue's cited file/line is stale, and the surrounding design changed.**
   The issue points at `src/Client/AmqpMessageDecoder.php:17-19` with an
   `array_values()` immediately after `decodeMessage()`. That logic now lives
   in `Message::applyDecodedSections()` (`src/Client/Message.php:225`) because
   the decoder was made lazy (#405-era work). A subagent that grepped the
   literal snippet in the issue's file would have found nothing, but the bug
   is real; the task brief's pointer to `Message.php:227` was correct.

2. **Building the test fixtures by hand is fiddly, and the decoder's strict
   size accounting (#453) rejects an off-by-one.** My first list8 fixture used
   `size = 4` where it had to be `size = content + 1 count byte = 7`, and
   `AmqpDecoder` correctly threw `List8 count exceeds available data`
   (`readList8`, `src/Client/AmqpDecoder.php:678`). No production issue — just
   a reminder that `size` includes the count field, which the new tests
   document inline.

3. **No AmqpValue helper existed in the target test file.** The task said to
   use the existing helpers, but `AmqpMessageDecoderTest` only has Data /
   Properties / ApplicationProperties builders; its two AmqpValue tests inline
   the raw bytes. I added a minimal `buildAmqpValueSection()` mirroring the
   sibling `AmqpDecoderMessageTest` rather than a larger inline blob.

## Discovered bugs / places to improve (including outside this issue's scope)

### 1. `AmqpDecoder` labels both `0x76` and `0x77` as "AmqpSequence" (documentation)

- **Where:** `src/Client/AmqpDecoder.php:220-223`.
- **What:** `case 0x76:` and `case 0x77:` share the comment
  `// AmqpSequence (body)`. Per AMQP 1.0, `0x76` is `AmqpSequence` and `0x77`
  is `AmqpValue`. The comment is misleading — it is the direct subject of this
  issue and made it slower to confirm which descriptor the bug report meant.
- **Suggested fix:** split/relabel the cases:
  `case 0x76: // AmqpSequence` and `case 0x77: // AmqpValue`, or use one
  comment `// AmqpSequence (0x76) / AmqpValue (0x77) (body)`.
- **Severity:** nit.

### 2. Non-scalar / empty AMQP map keys silently collapse to `''` and overwrite

- **Where:** `src/Client/AmqpDecoder.php:742-743` (`readMap8`) and
  `:841-842` (`readMap32`).
- **What:** `$mapKey = is_int($key) ? $key : (is_scalar($key) ? (string) $key : '');`
  A map whose key is `null`, a list, or a map (legal AMQP values; AMQP lets
  map keys be any type) is stored under `''`, and two such distinct keys
  silently overwrite each other (last wins). This class of loss is the same
  "keys disappear without a warning" family as #462, but it is decoder-level,
  not the `array_values()` post-processing fixed here, and out of scope.
- **Suggested fix:** reject non-scalar map keys with a
  `DeserializationException` (consistent with the #489 odd-count and #453
  consumed-bytes guards), or use a stable stringified representation
  (`get_debug_type()`-qualified) so distinct keys cannot collide. Rejecting is
  the safer, smaller option.
- **Severity:** low/medium (security-adjacent: untrusted server data can be
  silently dropped).

### 3. Numeric AMQP map keys are coerced to PHP `int` keys

- **Where:** `src/Client/AmqpDecoder.php:742` / `:841`.
- **What:** The decoder deliberately casts non-int scalar keys to `string`, but
  PHP then re-coerces integer-looking strings back to `int` array keys
  (`'1'` stays `1`). So an AMQP map `{"1": "a"}` and `{1: "b"}` are
  indistinguishable in the returned PHP array, and a float/`true` key also
  lands on `1`. Inherent to PHP arrays; noted rather than filed.
- **Suggested fix:** none practical without a custom map value object — out of
  scope. Document that map keys round-trip through PHP's array-key rules.
- **Severity:** nit / documentation.

## Not findings (verified)

- `array_values()` is used elsewhere (`src/Client/Connection.php:302`,
  `src/Client/Consumer.php:517`, `Routing/KeyRoutingStrategy.php:34`) but on
  genuine lists/collections, where re-indexing is correct. Left untouched.
- No caller in `src/` reads `getBody()` in a way that assumes a list; all unit
  tests pass after the change.
