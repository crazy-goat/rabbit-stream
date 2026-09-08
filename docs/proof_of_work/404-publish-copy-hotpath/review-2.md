# 404 — review round 2

Checks run on `feature/issue-404-publish-copy-hotpath` @ 4f61ae4 (clean tree):

- `composer cs` — OK (PHPCS, no findings)
- `composer phpstan` — OK, 0 errors (level 9)
- `composer rector` (dry-run) — OK, no changes proposed
- `./vendor/bin/phpunit --testsuite unit` — OK, 1075 tests / 8195 assertions

## Round-1 finding dispositions (reviewer's round 2)

1. **no direct `toWire()` unit tests — FIXED, agree.**
   `tests/VO/PublishedMessageV2Test.php` covers exact wire bytes for a populated
   message (verified by hand: `pack('Jn',1,6) . "filter" . pack('N',4) . "body"`
   matches `\x00…\x01\x00\x06filter\x00\x00\x00\x04body`), empty filter+body
   (14 null bytes = 8 + 2 + 2 + 2... actually 8 + 2 + 0 + 4 + 0 = 14 ✓),
   negative publishingId, filter length 32768 (just above INT16_MAX), and
   invalid UTF-8 (`\xFF`). Exceptions are the project
   `CrazyGoat\RabbitStream\Exception\InvalidArgumentException`, which extends
   `\InvalidArgumentException`, so the tests' `\InvalidArgumentException`
   expectations hold either way.

2. **validation constants duplicated in three classes — deliberately not fixed, agree.**
   `WireEncodeTrait` is a cross-cutting refactor of `PublishedMessage`,
   `PublishedMessageV2` and `WriteBuffer`; correctly deferred and recorded in
   `findings-coder.md`. No new duplication was added by round 1's fix
   (`UINT64_MAX`/`INT16_MAX`/`INT32_MAX` are private to the VO, mirroring V1).

3. **validation order (length before UTF-8) — not a real finding, agree.**
   Both `WriteBuffer::addString()` and `toWire()` reject the same set of inputs;
   only the exception identity for doubly-invalid (too-long AND non-UTF-8)
   data differs, which nothing depends on. Note `addString()` also validates
   length first (it validates UTF-8 only after computing length — actually
   length check is in `validateInt` after the UTF-8 branch at line 158–162,
   but either order accepts/rejects the same inputs). Not reopened.

4. **`pack('J')+pack('n')` vs `pack('Jn')` — FIXED, agree.**
   `src/VO/PublishedMessageV2.php` now has a single
   `pack('Jn', $this->publishingId, $filterLength)`, matching `PublishedMessage::toWire()`
   style. Output unchanged.

5. **dead upper-bound publishingId check on 64-bit — deliberately not fixed, agree.**
   `PHP_INT_MAX` is `UINT64_MAX` on 64-bit so `$this->publishingId > self::UINT64_MAX`
   is unreachable there, but it is live on 32-bit PHP and preserves parity with
   V1's `PublishedMessage::toWire()`. Keeping it is correct defensive coding.

## Verification of new test file and pack('Jn') change

- Test expectations hand-verified against the protocol layout
  (uint64 publishingId, int16-prefixed filterValue, int32-prefixed body) — correct.
- `toWire()` and `toStreamBuffer()` use the same validation limits
  (INT16_MAX = 32767, INT32_MAX = 2147483647) and the same
  `InvalidArgumentException` class — behaviour parity confirmed.
- `PublishRequestV2::toStreamBuffer()` now concatenates `$message->toWire()`;
  the docblock was updated to match. No other callers of
  `PublishedMessageV2::toStreamBuffer()` were changed, and `toStreamBuffer()`
  is retained (interface requirement).

## New issues in the full diff

None found in `src/` or `tests/`. Minor observations, neither blocking:

- (info) `tests/VO/PublishedMessageV2Test.php` uses `\InvalidArgumentException`
  rather than the project exception class. Since the latter extends it, this is
  arguably looser-but-portable; not worth churn.
- (info) Round-1 statement in dispositions that UTF-8 check order "both orders
  reject the same inputs" is accurate for the shipped code.

## Verdict

**Clean.** No open findings.
