# Review 1 — issue #404 (publish copy hotpath), branch `feature/issue-404-publish-copy-hotpath`

Scope reviewed: `git diff origin/main...HEAD` — 2 source files changed
(`src/VO/PublishedMessageV2.php`, `src/Request/PublishRequestV2.php`) plus
docs/proof-of-work files.

## Local QA results (all run on this branch)

| Command | Result |
|---|---|
| `composer cs` | OK, no errors |
| `composer phpstan` | OK, level 9 clean |
| `composer rector` (dry-run) | OK, no suggestions |
| `./vendor/bin/phpunit --testsuite unit` | OK — 1070 tests, 8190 assertions |

## Check 1: Wire output byte-identical to before?

**Yes.** Old path: `(new WriteBuffer())->addUInt64(...)->addString(...)->addBytes(...)`
produces `pack('J') + pack('n') . filterValue + pack('N') . message`.
New `PublishedMessageV2::toWire()` produces exactly the same byte sequence:
`pack('J', id) . pack('n', strlen(filter)) . filter . pack('N', strlen(body)) . body`.

Verified against `WriteBuffer`:

- `addUInt64` → validate `0..PHP_INT_MAX`, `pack('J')` — identical range, identical
  error message ("Value X is out of range for uint64 (0 to ...)").
- `addString` (non-null) → UTF-8 check, length `0..32767`, `pack('n')` — identical
  range and messages; `PublishedMessageV2::$filterValue` is non-nullable `string`,
  so the null (`0xFFFF`) branch is unreachable on both paths.
- `addBytes` (non-null) → length `0..INT32_MAX`, `pack('N')` — identical.

`PublishRequestV2::toStreamBuffer()` header (`pack('nnCN', ...)` + count) unchanged;
only the per-message body source switched from
`$message->toStreamBuffer()->getContents()` to `$message->toWire()`. The existing
`tests/Request/PublishRequestV2Test.php` byte-exact expectations still pass,
confirming end-to-end frame equality.

## Check 2: Validation parity

Functionally equivalent for all inputs (see above). One ordering nuance, recorded
as a low-severity finding: `WriteBuffer::addString()` checks UTF-8 **before** the
length, whereas `toWire()` checks length **before** UTF-8. For a value violating
both constraints the exception type/message differs (length message vs UTF-8
message). Single-fault inputs throw identically. Cosmetic only.

## Check 3: PHPStan 9 / PSR-12

Clean. Constants are `private const`, types fully declared, imports grouped
correctly, new `InvalidArgumentException` import present.

## Check 4: Test coverage for the new `toWire()` path

Indirect coverage exists (the request-level tests now exercise `toWire()` via
`toStreamBuffer()` and pass), but there are **no direct unit tests** for
`PublishedMessageV2::toWire()` — notably none for the three validation branches
(negative/oversized publishingId is unreachable on PHP 64-bit for >max but
negative is reachable; oversized filterValue; invalid UTF-8 filterValue; oversized
body). The V1 `PublishedMessage::toWire()` has the same gap, so the new code
merely mirrors existing coverage — low severity, still worth fixing.

## Findings summary

1. **Low** — no direct tests for `PublishedMessageV2::toWire()` validation branches.
2. **Low** — validation-limit constants (`UINT64_MAX`, `INT16_MAX`, `INT32_MAX`)
   now duplicated in three classes (`PublishedMessage`, `PublishedMessageV2`,
   `WriteBuffer`); drift risk. Follow-up: shared trait.
3. **Low** — validation order (length vs UTF-8) differs from `addString()` for
   doubly-invalid input.
4. **Nit** — `pack('J')` and `pack('n', $filterLength)` could be a single
   `pack('Jn', ...)` call, matching `PublishedMessage::toWire()`'s `pack('JN', ...)`.
5. **Nit** — the negative-`publishingId` check (`< 0`) is dead code in practice
   (protocol ids are assigned by `Producer` starting at 0) but harmless and
   consistent with V1.

Nothing blocks merge. All findings are non-blocking hardening/follow-up items.
