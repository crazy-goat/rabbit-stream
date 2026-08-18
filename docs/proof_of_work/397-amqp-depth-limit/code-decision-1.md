# Code decision 1 — AmqpDecoder recursion depth limit (issue #397)

**Issue:** #397
**Branch:** `feature/issue-397-amqp-depth-limit`

## Problem

`src/Client/AmqpDecoder.php` recursed without a depth limit: `decodeValue()`
→ `readList8/32`/`readMap8/32`/`readDescribedType`/`readDescribedTypeWithPosition`
→ `decodeValue()` … . AMQP 1.0 compound types may nest arbitrarily, so a
publisher can send a message whose body is ~750 KB of nested `0xc0` list8
frames and kill a consumer worker with `memory_limit=128M` via an
**uncatchable** `Allowed memory size exhausted` fatal. Verified before the
fix: `str_repeat("\xc0\xff\x01", 2_000_000)` (6 MB, under the 8 MB frame
limit) exhausted 1 GB and died; with `memory_limit=128M` the same crash
needed only ~750 KB.

## Approach taken

Four surgical changes to `src/Client/AmqpDecoder.php`:

1. **Class constant** `private const MAX_RECURSION_DEPTH = 32;` — matches the
   codebase convention (`Consumer::MAX_UINT16`, `WriteBuffer` min/max consts).
   PHP 8.1 is the floor here, so no typed class constants (those are 8.3).

2. **`decodeValue()`** gained two OPTIONAL parameters, keeping the public API
   backward compatible:
   ```php
   public static function decodeValue(
       string $data,
       int $position,
       int $depth = 0,
       int $maxDepth = self::MAX_RECURSION_DEPTH
   ): array
   ```
   The check is one guard at the top, right after the end-of-data check:
   ```php
   if ($depth > $maxDepth) {
       throw new DeserializationException(sprintf(
           'AMQP recursion depth limit exceeded (max %d)', $maxDepth
       ));
   }
   ```
   Placing it in `decodeValue()` rather than in each reader means **every**
   recursive path (list element, map key, map value, described-type
   descriptor, described-type value, both described-type readers) is covered
   by a single guard — there is only one way into recursion in this class.

3. **Private readers** (`readList8`, `readList32`, `readMap8`, `readMap32`,
   `readDescribedType`, `readDescribedTypeWithPosition`) now take
   `int $depth, int $maxDepth` and pass `$depth + 1` into every nested
   `decodeValue()` call. Being private, their signatures are free to change.

4. **`decodeMessage()`** gained one optional parameter
   `int $maxDepth = self::MAX_RECURSION_DEPTH` and starts sections at
   `readDescribedTypeWithPosition($data, $position, 0, $maxDepth)`.

Depth accounting: `decodeValue($data, 0)` starts at depth 0; every recursive
descent (compound element, descriptor, described value) adds 1. Depth 32
decodes fine; depth 33 throws — exactly the "depth == limit ok,
depth == limit+1 throws" boundary the issue asks for. Real AMQP 1.0 messages
(header/properties/annotations + body) nest in the low single digits, so 32
is generous.

## What I rejected and why

1. **Constant-only limit** (no `$maxDepth` param). The issue's acceptance
   criterion says "configurable, default <= 32". A hard-coded constant is only
   configurable by editing source, which is not enough for an operator
   hardening against hostile publishers without redeploying. The
   optional-parameter approach is still fully backward compatible, so the
   small extra surface was worth it.

2. **Counting depth inside the readers instead of in `decodeValue()`.**
   Duplicates the check across six readers and makes it easy to miss a new
   recursive reader later. One guard in `decodeValue()` is the single choke
   point.

3. **`$depth` with no `$maxDepth` on `decodeMessage()`.** Would have left the
   Consumer-path (the actual exposure) unconfigurable; `decodeMessage()` is
   the function `Consumer::read()` → `AmqpMessageDecoder::decode()` actually
   calls.

4. **Higher default (64/128) or computed-from-frame-size.** 32 matches the
   issue's "well under 32 levels" guidance and caps the stack cost so tightly
   that the PoC now throws with a measured ~0 extra MB of peak memory.

## What I was unsure about

1. **Where exactly to place the check.** Top of `decodeValue()` (before the
   match) costs one integer compare on *every* decoded value, including
   scalars — negligible but nonzero. Inside the compound readers only would
   skip the check for the described-type descriptor/value paths unless
   duplicated. I chose the single top-of-function guard: simplest, cannot be
   bypassed by a new format-code arm that recurses.

2. **Whether to change `decodeMessage()`'s signature at all.** The design note
   flagged it as optional. I added the `$maxDepth` param because the issue's
   exposure path is `decodeMessage()`, and "configurable" was an acceptance
   criterion; an optional trailing param breaks no caller (verified: only
   `AmqpMessageDecoder::decode()` and tests call it, all with one argument).

3. **Boundary semantics of `>` vs `>=`.** `>` gives depth==limit accepted —
   matching the issue's requested boundary test precisely.

4. **Test-helper `chr()` range.** PHPStan (level 9) rejects `chr()` with an
   unbounded `int<2, max>`; the house pattern for size bytes is
   `chr($size & 0xFF)` (see `tests/Client/AmqpDecoderMessageTest.php`), which
   I adopted. The mask is faithful here: the builder only ever emits sizes
   ≤ 100 for the depths used.

## Validation

- `./vendor/bin/phpunit --testsuite unit` — 649 tests, 1402 assertions, the
  only "risky" being the pre-existing no-assertion
  `StreamConnectionTest::testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`.
- PoC run standalone with `memory_limit=128M` and the full 6 MB payload:
  throws `DeserializationException` ("AMQP recursion depth limit exceeded
  (max 32)"), peak memory ~8 MB, catchable via
  `RabbitStreamExceptionInterface`.
- `composer phpstan` (level 9), PHPCS PSR-12, Rector dry-run: all clean.
- E2E not run: this is the AMQP 1.0 message-body decoder, not a wire-level
  protocol change (per the issue instructions).
