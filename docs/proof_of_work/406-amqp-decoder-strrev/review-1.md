# Review round 1 — issue #406 (AmqpDecoder `strrev(substr())` → offset-form `unpack()`)

Reviewer: code-review subagent. Review-only: no source/test code modified, nothing
committed. Branch: `feature/issue-406-amqp-decoder-strrev`.

## Scope of the diff

`git diff origin/main...HEAD --stat`:

```
 .../406-amqp-decoder-strrev/code-decision-1.md     | 66 ++++++++++++++++
 .../406-amqp-decoder-strrev/findings-coder.md      | 60 +++++++++++++++
 tests/Client/AmqpDecoderTest.php                   | 90 ++++++++++++++++++++++
 3 files changed, 216 insertions(+)
```

No production code changed. The only behavioural change is 90 added lines in
`tests/Client/AmqpDecoderTest.php`. This is correct given the issue was already
fixed on `main` (verified below).

## 1. The "already fixed on main" claim — VERIFIED

- `git log -S "strrev" -- src/Client/AmqpDecoder.php` returns exactly two commits:
  - `d392ab3` feat: implement AMQP 1.0 message decoder (#51) — introduced it
  - `25b4507` perf: harden consumer/producer hot path … (closes #492) (#493) — removed it
- `git merge-base --is-ancestor 25b4507 origin/main` → exit 0 (it is on `main`).
- `git show origin/main:src/Client/AmqpDecoder.php | grep -n strrev` → no matches.
- `grep -rln strrev src/` → no files (exit 1).
- `grep -rn strrev tests/` → one hit, a **comment** only:
  `tests/Client/AmqpDecoderTest.php:263` ("…with no substr()/strrev() slicing…").
  There is no executable `strrev` call anywhere.
- Offset-form `unpack()` is genuinely in place: `src/Client/AmqpDecoder.php:291`
  and `:305` both call `unpack($format, $data, $position)` with an absolute
  offset; the integer readers pass big-endian `'n'`/`'N'`/`'J'` and the
  float readers pass `'G'`/`'E'`. No `substr()` precedes any unpack.
- The old `unpackInt` → `safeUnpack` → `is_scalar` chain is gone: no
  `safeUnpack`/`unpackInt(` function remains. (`is_scalar` survives at
  `:742`/`:841` only as map-key normalisation, unrelated to unpacking.)

**Conclusion: the branch correctly does *not* touch production code; the coder's
central claim is accurate.**

## 2. Boundary-test correctness — VERIFIED case by case

All 27 `numericBoundaryProvider` cases plus `testDecodeNumericAtNonZeroOffset`
were checked against the AMQP 1.0 format codes and PHP `pack()`/`unpack()`
semantics. Every fixture uses the correct big-endian pack code for its wire
width, and every expectation matches the decoder contract:

| format | code | fixture pack | signedness in decoder | expectation |
|--------|------|--------------|-----------------------|-------------|
| ubyte  | 0x50 | `C`          | `ord()` (unsigned)    | 0 / 255 |
| byte   | 0x51 | `c` (signed) | `unpack('c')`         | -128 / 127 |
| ushort | 0x60 | `n`          | `unpack('n')`         | 0 / 65535 |
| short  | 0x61 | `n`          | `'n'` + manual `-0x10000` | -32768 / 32767 / -1 |
| uint   | 0x70 | `N`          | `unpack('N')`         | 0 / 4294967295 |
| int    | 0x71 | `N`          | `'N'` + manual `-0x100000000` | -2147483648 / 2147483647 / -1 |
| ulong  | 0x80 | `J`          | `unpack('J')` (native signed PHP int) | 0 / PHP_INT_MAX (representable subset) |
| long   | 0x81 | `J`          | `unpack('J')`         | PHP_INT_MIN / PHP_INT_MAX / -1 |
| timestamp | 0x83 | `J`      | `unpack('J')`         | PHP_INT_MIN / -1000 / 0 / PHP_INT_MAX |
| float  | 0x72 | `G` (big-endian 32-bit) | `unpack('G')` | -1.5 / 1.0 |
| double | 0x82 | `E` (big-endian 64-bit) | `unpack('E')` | -1.5 / 1.0 |

Spot-checks run directly against the decoder confirmed all values and positions:

```
ubyte max      got=255                    pos=2 len=2 OK
byte min       got=-128                   pos=2 len=2 OK
short min      got=-32768                 pos=3 len=3 OK
uint max       got=4294967295             pos=5 len=5 OK
int min        got=-2147483648            pos=5 len=5 OK
ulong max      got=9223372036854775807    pos=9 len=9 OK
long min       got=-9223372036854775807-1 pos=9 len=9 OK
timestamp neg  got=-1000                  pos=9 len=9 OK
float          got=-1.5                   pos=5 len=5 OK
double         got=-1.5                   pos=9 len=9 OK
```

Observations:

- **Endianness is genuinely pinned.** On this (little-endian) host a regression
  from `'G'`/`'E'` to machine-endian `'f'`/`'d'` would decode `pack('G', -1.5)`
  as a denormal, so the float/double cases fail. Likewise a regression from
  `'n'`/`'N'`/`'J'` to little-endian forms fails. The fixtures are not
  self-fulfilling.
- **Sign correction is genuinely pinned.** Dropping the manual correction in
  `readInt16()`/`readInt32()` makes `short min`/`int min`/`… minus one` fail;
  switching `readInt8()` from `'c'` to `'C'` makes `byte min` fail.
- **`assertSame` strictness is safe.** Integer expectations are PHP ints on a
  64-bit build (guarded by `Platform::assertSixtyFourBitIntegers()`), and the
  only float expectations (`-1.5`, `1.0`) are exactly representable in both
  IEEE-754 binary32 and binary64, so no float/int coercion or precision
  mismatch can occur. `readUint32()`/`readInt32()`/`readInt64()` are declared
  `: int`, so values are ints, not floats.
- **Whole-fixture consumption is asserted** for every provider case
  (`strlen($encoded) === $pos`), so a reader that decodes the right value but
  advances the cursor wrongly is also caught.
- **Coverage against the acceptance criteria is meaningful.** Signed widths all
  cover min, max and (16/32/64-bit) `-1`; timestamp additionally covers a
  pre-epoch negative and zero; floats are covered with a negative. The only
  deliberately omitted case is `ulong` above `PHP_INT_MAX`, which is the
  pre-existing bug reported by the coder (see §4) and out of this issue's scope.

### `testDecodeNumericAtNonZeroOffset` — VERIFIED

```php
$data = "\x71" . pack('N', 0x7FFFFFFF) . "\x71" . pack('N', 0x80000000);
```

- First `decodeValue($data, 0)`: format byte at 0 → `readInt32` at 1..4 →
  returns 2147483647, position **5**. Correct.
- Second `decodeValue($data, 5)`: format byte at 5 → `readInt32` at 6..9 →
  returns -2147483648, position **10**. Correct.
- The second read genuinely exercises a non-zero `$position` passed to
  `unpack($format, $data, $position)`, i.e. exactly the offset form the issue
  asked for. The 5/10 assertions are right.

## 3. Commands run (all pass)

| command | result |
|---------|--------|
| `composer cs` (PHPCS PSR-12) | `277/277` files, **exit 0**, no violations |
| `composer phpstan` (level 9) | `271/271`, `[OK] No errors`, exit 0 |
| `composer rector` (dry-run) | `2/2`, `[OK] Rector is done!`, exit 0 |
| `./vendor/bin/phpunit --testsuite unit` | `OK (1180 tests, 8631 assertions)`, exit 0 |
| `composer lint` (full gate incl. kb-lint) | all OK; kb-lint `10 entries, 0 warning(s), 0 stale`; exit 0 |
| `./vendor/bin/phpunit --filter testDecodeNumeric --testdox` | `OK (28 tests, 58 assertions)` — 27 provider cases + offset test |

## 4. The `ulong` overflow finding — REAL

Evidence (`src/Client/AmqpDecoder.php:378-386`):

```php
private static function readUint64(string $data, int &$position): int
{
    ...
    $value = self::unpackIntAt('J', $data, $position, 'uint64');
    $position += 8;
    return $value;               // no < 0 guard
}
```

`unpackIntAt()` does `unpack('J', …)` and casts to `int`. On a 64-bit build
`unpack('J', "\xff"×8)` already yields `int(-1)`, so an AMQP `ulong`
(`0x80`) with the top bit set silently wraps negative. Live check:

```
AmqpDecoder ulong 0xFFFF... => int(-1)
ReadBuffer threw: CrazyGoat\RabbitStream\Exception\DeserializationException:
  uint64 value 0xffffffffffffffff at position 0 exceeds PHP_INT_MAX
```

`ReadBuffer::getUint64()` (`src/Buffer/ReadBuffer.php:107-125`) was hardened in
commit `dea2076` (closes #393) with exactly the `if ($data[1] < 0) throw`
check that `AmqpDecoder` lacks. So the two decoders genuinely disagree. The
coder's finding is accurate and **not overstated**; it is pre-existing and
correctly left out of this test-only change.

## 5. Coder findings audit

- Finding #1 `ulong` wrap — accurate (see §4).
- Finding #2 `Data`-section concat at `:216-217` is O(n²) — accurate: the code
  is literally `$sections['body'] = (is_string($currentBody) ? $currentBody : '') . $value;`
  in the loop. Pre-existing, low impact for typical single-section messages.
- Finding #3 dead `false` checks in `unpackIntAt`/`unpackFloatAt` (`:289-310`) —
  accurate; `unpack()` with these fixed literal formats and the preceding
  bounds guards cannot return `false`. Harmless defensive code.
- Finding #4 `readInt64()` comment wording (`:393-395`) — accurate nit: `'J'`
  is an unsigned format; the bit pattern is *reinterpreted* as a signed PHP int.
  The conclusion (no manual correction) is right.
- `code-decision-1.md` claim that the acceptance-criterion tests were the only
  thing still open — consistent with the evidence.

No coder claim was found wrong or overstated.

## Verdict

**Code looks good, no issues to fix.**

The branch is a correct, minimal resolution: the production change was already
on `main`, and the added tests are accurate, non-self-fulfilling and cover the
min/max/negative boundaries across every fixed-width numeric format. All lint,
static-analysis and unit gates pass. The only real defect identified
(`AmqpDecoder::readUint64()` diverging from `ReadBuffer::getUint64()` on
`ulong > PHP_INT_MAX`) is pre-existing, correctly reported rather than silently
patched in a test-only PR, and should be tracked as a follow-up issue.

## Proposed knowledge-base entry (describe only — not written)

Candidate FAQ entry, tag `protocol`:

> **`unpack('J')` has no unsigned 64-bit PHP type — guard `ulong` reads.**
> `unpack('J', …)` returns a *native signed* PHP int, so an AMQP `ulong`
> (0x80) above `PHP_INT_MAX` comes back negative rather than as a large
> unsigned value. Any reader that must reject it (e.g.
> `ReadBuffer::getUint64()`, hardened in #393) has to check `< 0` explicitly —
> unlike `long`/`timestamp`, which are signed on the wire and need no such
> check. `AmqpDecoder::readUint64()` still lacks that guard, so the two
> decoders disagree; verify both whenever touching 64-bit numeric decoding.
