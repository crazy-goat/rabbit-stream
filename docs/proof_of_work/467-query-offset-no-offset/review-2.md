# Review 2 — Issue #467: treat QueryOffset `NO_OFFSET` as a normal outcome (convergence)

- **Branch:** `feature/issue-467-query-offset-no-offset`
- **HEAD:** `61dbf0a` (`fix(client): consume NO_OFFSET offset field, pin 0-vs-null and response code; correct BC note`)
- **Base reviewed:** `git diff main...HEAD` (round-1 was on `5147ced`; this round confirms the fix commit `61dbf0a`)
- **Reviewer:** review-critical subagent (read-only w.r.t. source)
- **Round:** 2 (convergence)

## Verdict

**Converged. No high, medium or low *code* findings remain.** All seven round-1
findings are fixed and the fixes are correct, including on the real wire
(E2E against RabbitMQ passes). One **low** documentation-accuracy finding and
two **nit** documentation findings are reported below; none touch `src/` or the
tests and none block merge. If "open findings" means source behaviour, the
branch is clean.

---

## Round-1 findings — disposition with evidence

| ID | Round-1 severity | Round-2 status | Evidence |
|----|------------------|----------------|----------|
| R1-F1 | low | **Fixed (accurate)** | `CHANGELOG.md:13` now reads "this does **not** force existing external implementors to change — PHP return types are covariant, so an implementation declaring the narrower `int` still satisfies the widened interface. The impact is on **callers**, which may now receive `null`…". Factually correct: PHP return types are covariant, and all in-repo implementations declare `?int` anyway. |
| R1-F2 | low | **Fixed (correct)** | `src/Response/QueryOffsetResponseV1.php:58` now calls `$buffer->getUint64()` on the `NO_OFFSET` branch. Verified the field is genuinely on the wire: `rabbit_stream_core.erl` `response_body({query_offset, Tag, Code, Offset}) -> {command_id(Tag), <<Code:16, Offset:64>>}` encodes the trailer unconditionally, and `rabbit_stream_reader.erl:2339` sends `{?RESPONSE_CODE_NO_OFFSET, 0}`. The E2E "Query offset for non existent reference" passes against the real broker, so the read succeeds. |
| R1-F3 | low | **Fixed (meaningful)** | `tests/Response/QueryOffsetResponseV1Test.php` `testZeroOffsetIsNotConfusedWithNoOffset` uses `assertSame(0, $response->getOffset())`, which is `0 === null`-strict. The OK path still takes `$buffer->getUint64()` (`QueryOffsetResponseV1.php:64`), so a future falsy-collapse would fail the test. |
| R1-F4 | nit | **Fixed** | `src/Client/SuperStreamConsumer.php:134-154` carries a full docblock (`null` = normal `NO_OFFSET`, `@return int|null`, `@throws` set). |
| R1-F5 | nit | **Fixed (documented + pinned)** | `fromArray()` docblock (`QueryOffsetResponseV1.php:73-82`) states a missing key equals explicit `null`; pinned by `testQueryOffsetResponseFromArrayWithNullOffset` and `testQueryOffsetResponseFromArrayWithMissingOffsetIsNoOffset`. |
| R1-F6 | nit | **Fixed** | `docs/en/api-reference/enums.md:171` now "No offset stored yet (normal `QueryOffset` reply, not an error)". |
| R1-F7 | nit | **Fixed** | `testThrowsOnErrorResponseCode` catches `ProtocolException` and asserts `getResponseCode() === ResponseCodeEnum::STREAM_NOT_EXIST` plus the `0x0002` message. |

### Specific questions from the brief

- **R1-F2 — is the `uint64` consumed and the full-frame assertion meaningful
  (`getPosition()` semantics)?** Yes. `ReadBuffer::getPosition()` is
  window-relative (`src/Buffer/ReadBuffer.php:309-312`), and the test builds the
  buffer with `offset = 0` over the raw payload, so `windowLength ===
  strlen($raw)`. After key(2)+version(2)+correlation(4)+code(2)+offset(8) the
  cursor is at 18 = `strlen($raw)`; the pre-fix parser stopped at 10 and the
  assertion would have failed. The assertion is therefore a true
  full-consumption gate. (Coder's own note in `findings-coder.md` — the
  assertion is only valid because the buffer is not a sub-window — is correct,
  but the current test is fine.)
- **R1-F1 — is the CHANGELOG statement accurate?** Yes (see table).
- **R1-F3 — does the OK+0 test distinguish `0` from `null`?** Yes, strict
  `assertSame` on the typed `?int` accessor.
- **Behaviour regression in `defaultConsumerUpdateHandler()`?** No.
  `src/Client/Consumer.php:455-461` replaced the
  `catch (ProtocolException) / getResponseCode() === NO_OFFSET` branch with
  `$offset === null ? $this->offset : OffsetSpec::offset($offset)`. The null
  case is produced by exactly the same broker answer (`NO_OFFSET`), all other
  non-OK codes still throw (`assertResponseCodeOk`), and the resume value is the
  same initial `OffsetSpec` as before. `testSingleActiveConsumerResumesAtInitialOffsetWhenNoOffsetStored`
  and the E2E `SingleActiveConsumerE2E` pass.
- **Did widening the interfaces to `?int` break PHPStan/covariance?** No. Only
  three classes implement the interfaces (`Connection`, `Consumer`,
  `SuperStreamConsumer`), all declaring `?int`; PHPStan level 9 is clean over
  `src/` **and** `tests/` (`phpstan.neon` paths include `tests`), and the E2E
  suite compiles/passes. Narrowing implementations to `int` would also be legal
  (covariance), so no external implementor breaks.

---

## New findings (round 2)

### R2-F1 — `docs/helpers/faq.md` FAQ-007 still teaches `NO_OFFSET` as a `ProtocolException` (low)

- **File:line:** `docs/helpers/faq.md:126`
- **What happened:** FAQ-007 states non-OK codes "are asserted inside
  `SimpleCorrelatedResponseV1::fromStreamBuffer()` … **'stream already exists',
  'no offset stored' (#467)** and invalid SASL credentials all follow this
  path". After #467, `QueryOffsetResponseV1::fromStreamBuffer()` intercepts
  `NO_OFFSET` and returns a response with a `null` offset; it never calls
  `assertResponseCodeOk()` for `0x13`, so "no offset stored" no longer surfaces
  as a `ProtocolException`. The entry (status `active`) is now factually
  incorrect. The branch did not touch this file (`git diff main...HEAD` omits
  it), but the behaviour change is what invalidated it.
- **Impact:** a maintainer following FAQ-007 while documenting a `Connection`
  method will document a throw that no longer exists — the same class of error
  the entry was created to prevent.
- **Check that could catch it:** `kb-lint` validates structure/tags only, not
  semantics. A repo-wide grep for `NO_OFFSET` in docs when changing its
  handling (or a KB entry that points at `QueryOffsetResponseV1`) would have
  surfaced it. Suggested fix: drop "no offset stored" from the example (or note
  it is now a normal `null` reply) and keep "stream already exists" /
  "invalid credentials".

### R2-F2 — `SuperStreamConsumer::queryOffset()` docblock omits the unnamed-consumer throw (nit)

- **File:line:** `src/Client/SuperStreamConsumer.php:147-148`
- **What happened:** the new docblock documents
  `@throws ProtocolException If the broker returns a non-OK response code other
  than NO_OFFSET`, but `createSuperStreamConsumer()` accepts `?string $name =
  null` (`src/Client/Connection.php:1236`), and a nameless consumer makes the
  delegated `Consumer::queryOffset()` throw `ProtocolException('Cannot query
  offset for unnamed consumer')` (`src/Client/Consumer.php:767-769`). The API
  reference `docs/en/api-reference/super-stream-consumer.md:231` *does* list
  this case ("If this consumer was created without a `$name`, or …"), so the
  source docblock is the incomplete one.
- **Impact:** doc-only; the `@throws` set is understated by one reachable path.
- **Check that could catch it:** none — the reflection docblock gates
  (`ConsumerDocblockTest`/`ConnectionDocblockTest`) assert presence, not
  completeness, and there is no equivalent gate for `SuperStreamConsumer`
  (already noted by the coder).

### R2-F3 — offset-lag examples disagree on "nothing stored" (nit)

- **File:line:** `docs/en/examples/offset-resume.md:616-617` vs
  `docs/en/guide/offset-tracking.md:589-590`
- **What happened:** both `checkOffsetLag`/`getOffsetLag` examples were edited in
  this branch. `offset-resume.md` returns `0` when the stored offset is `null`
  ("Nothing stored yet"), i.e. treats a never-consumed consumer as **fully
  caught up**; `offset-tracking.md` maps `null → $storedOffset = 0` ("the whole
  stream is behind") and returns `latest - 0`, i.e. **maximally behind**. The
  two docs now contradict each other.
- **Impact:** doc-only; both are illustrative, and the `return 0` behaviour
  predates this branch. But the branch rewrote both snippets, so it had the
  chance to align them.
- **Check that could catch it:** `check-docs-links` only validates links;
  nothing cross-checks example semantics.

### Noted, not counted (pre-existing, already recorded by the coder)

`docs/en/examples/error-handling-patterns.md` (lines 339, 638) still teaches
catching `ResponseCodeEnum::NO_OFFSET` from a `StreamConnection::subscribe()`
call. The file is already unrunnable (`CrazyGoat\RabbitStream\OffsetSpecification`
and `StreamConnection::subscribe()` do not exist) and the coder logged it as
out-of-scope in `findings-coder.md` ("Out of scope", item 1), so it is not a
regression from this branch.

---

## Gate results (HEAD `61dbf0a`)

| Gate | Command | Result |
|------|---------|--------|
| Code style | `composer cs` | ✅ clean (279 files) |
| Static analysis | `composer phpstan` | ✅ no errors (level 9, `src` + `tests`) |
| Refactoring | `composer rector` | ✅ no changes suggested |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | ✅ 1222 tests, 8714 assertions |
| KB lint | `php bin/kb-lint.php` | ✅ 12 entries, 0 warnings, 0 stale |
| Docs links | `php bin/check-docs-links.php` | ✅ all relative links resolve |
| E2E (Docker) | `./run-e2e.sh` | ✅ 147 tests, 3059 assertions (incl. `StoreOffsetQueryOffsetE2E::testQueryOffsetForNonExistentReference` against the real broker) |

## Recommendation

Merge. The round-1 fixes are correct and broker-verified; R2-F1 (low) is a
one-line KB correction that can ride along, and R2-F2/R2-F3 are nit doc
touch-ups. No source or test change is required.
