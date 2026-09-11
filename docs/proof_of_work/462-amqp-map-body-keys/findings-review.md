# Findings — review — issue #462

One entry per finding. Format: `file:line | description | severity | what happened to it`.

## Core fix (verification, not findings)

- `src/Client/Message.php:225-233` | Merged `if (is_array || null || scalar)` is exactly equivalent to the old two branches (`is_array`/`is_scalar` are disjoint, `null` not scalar); only arrays lose the `array_values()` re-index, which is the fix. | — | Not a finding: logic verified correct.
- `src/Client/Message.php:229` | Map keys preserved (`{"a":1,"b":2}` round-trips); lists stay sequential (`$list[] =` in all list readers). | — | Not a finding: verified via scratch decode of both fixtures and byte-accurate hand-built frames.
- `src/Client/Message.php:227` (old) | No in-repo caller relied on the old re-indexing: `getBody()` has no other `src/` caller, `TypeCast::toArray()` is not used in `Client/`, and all E2E body assertions are strings or nested lists. | — | Not a finding: grepped `getBody()` across `src/` and `tests/E2E/`.
- `tests/Client/AmqpMessageDecoderTest.php:437-457` | Map test uses the lazy `AmqpMessageDecoder::decode()` path and would fail pre-fix (`array_values(['a'=>1,'b'=>2]) === [1,2]`). Fixture bytes: `c1 0b 04` = map8 size 11 (1 count + 10 content), count 4. | — | Not a finding: genuine regression test, fixture recalculated byte-for-byte.
- `tests/Client/AmqpMessageDecoderTest.php:459-478` | List test is a guard (passes before and after), as its comment states; fixture `c0 07 03` = list8 size 7 (1 + 6), count 3. | — | Not a finding: byte accounting correct.

## Findings

- `docs/en/api-reference/message.md:14,32,59,204` | The hand-maintained API reference still documents the body as `array<int, mixed>`, which no longer matches the widened `array<int\|string, mixed>` public contract in `Message.php:17,266`. | low | Deliberately not fixed here (reviewer read-only; outside the declared diff scope). Recommend the coder update the four occurrences before merge; no automated check (e.g. `composer cs`/`phpstan`) catches doc drift.
- `CHANGELOG.md:7` | `[Unreleased]` has no entry for #462; the behaviour change (map keys preserved in AmqpValue bodies) is user-visible. | low | Deliberately not fixed: AGENTS.md puts CHANGELOG (and README status) updates at merge time; task explicitly says note but do not edit. Add a `### Fixed` bullet before merging.
- `src/Client/AmqpDecoder.php:220-221` | `case 0x76:` and `case 0x77:` share the comment `// AmqpSequence (body)`; per AMQP 1.0 `0x76` is AmqpSequence and `0x77` is AmqpValue. Misleading while reviewing this exact area. | nit | Not a real finding for this PR: pre-existing, out of scope (also reported by the coder). Optional relabel.
- `src/Client/AmqpDecoder.php:742,841` | Non-scalar AMQP map keys (`null`, list, map) collapse to `''` and silently overwrite each other; `get_debug_type()`-safe rejection or stringification would avoid silent data loss. | low | Not a real finding for this PR: pre-existing decoder behaviour, out of scope; already documented in `findings-coder.md`. Worth a follow-up issue.
- `src/Client/AmqpDecoder.php:663,688` | `readList8`/`readList32` are annotated `@return array<int, mixed>` while `readArray8/32` use the stricter `list<mixed>`; the list readers are in fact `list`. | nit | Not a real finding for this PR: pre-existing annotation inconsistency; the widened body PHPDoc (`array<int\|string, mixed>`) is a superset and correct regardless. `phpstan` level 9 does not flag it.
- `tests/Client/AmqpMessageDecoderTest.php:437-457` | No test for a map with non-sequential integer keys (e.g. `{0:'x', 2:'y'}`), the shape where "keep as-is" differs from "reindex" beyond string-key loss. | nit | Deliberately not fixed: the string-key test already fails pre-fix and covers the reported bug; this would be a nice-to-have extra assertion, not a coverage gap.

## Checks that would catch / do catch these

- `composer cs` — catches PSR-12/style issues (none found).
- `composer phpstan` (level 9) — catches type-annotation errors on code (none found; does not check Markdown docs).
- `composer rector` — catches planned refactors (none).
- `./vendor/bin/phpunit --testsuite unit` — captures the regression (added test fails pre-fix, passes post-fix).
- No automated check exists for `docs/en/api-reference/message.md` PHPDoc drift or the CHANGELOG entry; both are manual review items.
