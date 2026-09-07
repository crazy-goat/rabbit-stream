# #403 Chunk CRC verification — Review Round 1 (2026-09-07)

Worktree: `/Users/piotr.halas/work/rabbit-stream-wt-403`, branch `feature/issue-403-chunk-crc`.
`docs/proof_of_work/403-chunk-crc/findings-review.md` did not exist before this round (round 1), so there are no earlier findings to re-check. The coder's own `findings-coder.md` and `code-decision-1.md` were reviewed as part of the change.

## Automated checks (all run locally in the worktree)

| Check | Result |
|---|---|
| `composer cs` (PHPCS PSR-12 + Slevomat) | ✅ clean |
| `composer phpstan` (level 9) | ✅ 0 errors |
| `composer rector` (dry-run) | ✅ no suggestions |
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1068 tests, 8185 assertions, OK |

## Protocol correctness review

- **CRC algorithm & scope**: `crc32()` (standard CRC-32 / IEEE 802.3, same polynomial & init as `erlang:crc32`) over exactly `dataLength` bytes starting at `$headerSize` — matches the osiris chunk format (CRC covers the data section only). Correct.
- **Unsigned read**: `chunkCrc` is now read via `getUint32()` (was `getInt32()`). `ReadBuffer::getUint32()` returns an exact non-negative int on 64-bit PHP, and 32-bit builds are rejected in the constructor (#458), so PHP's `crc32()` (unsigned on 64-bit) compares exactly. Correct.
- **Strict comparison `!==`**: both sides are `int`, no string/int coercion hazard. Correct.
- **Chunk first offset**: `$chunkFirstOffset` is read as `getUint64()` and used verbatim in the error message and returned tuple — unchanged behavior, correct.
- **Error precedence**: the CRC check is placed after the data-section bounds check (`$dataEnd = $headerSize + $dataLength` guarded above), so `substr()` cannot read past the buffer and truncated chunks still report the size-mismatch error. Verified correct; the two deliberately-corrupted sub-batch tests still reaching their intended assertions confirm it.
- **Empty chunk**: `dataLength = 0` → `crc32('') = 0`; header builders emit `crc32('') = 0` too, so empty chunks parse. No dedicated test, but the math is trivially consistent (see finding 4).

## Toggle design review

- Default `true` everywhere (`OsirisChunkParser::parse/parseEntries/parseMessages/parseRaw/parseRawViews/parseChunkHeader`, `Consumer::__construct`, `Connection::createConsumer`, `Connection::createSuperStreamConsumer`), threaded with named arguments — consistent with the `maxDecodeDepth` pattern. PHPStan level 9 clean; `private readonly bool $verifyCrc` typed correctly.
- Placement before `$onClose` in `Consumer::__construct` is a (theoretical) positional-calling break for existing users — see finding 2.

## Findings

1. **`docs/en/advanced/osiris-chunk-format.md:35` — header field documented as "Reserved (int32)"** — Bytes 32-35 is the `chunkCrc` field, which is now verified, not reserved. The doc is outdated w.r.t. this feature. Severity: **low**.
2. **`src/Client/Consumer.php:141-166` — new `$verifyCrc` param inserted before `$onClose`** — a caller passing `onClose` positionally (previously the last param) now hits the bool slot. Existing pattern (maxDecodeDepth had the same effect) mitigates; the coder's own findings-coder.md item 2/3 already flags the options-object debt. Severity: **nit**.
3. **`src/Client/OsirisChunkParser.php:370-384` — one extra full data-section copy (`crc32(substr(...))`) per chunk on the hot path when enabled (default)** — acknowledged in code-decision-1.md and findings-coder.md; not a correctness bug. Severity: **low** (performance note / follow-up).
4. **Missing test: empty-chunk (`dataLength = 0`) CRC path** and no unit test that `Consumer::__construct(verifyCrc: false)` actually propagates to the parser (only the parser-level toggle is tested). Severity: **low** (test coverage gap).

No high or medium findings. Wire format, error precedence, signedness, toggle threading, PSR-12, and PHPStan 9 are all correct.

## Could automated checks have caught these?

- Finding 1 (docs): no — no docs-lint covers prose accuracy; `composer kb-lint` only covers `docs/helpers/`.
- Finding 2 (positional break): no — PHPStan does not flag added optional params before other optional params.
- Finding 3: no — performance, not static analysis.
- Finding 4: no — coverage tools (`phpunit --coverage`) would surface the untested branches.

## Verdict

**Clean** — mergeable as-is; findings 1 and 4 are nice-to-have follow-ups.
