# Review — Round 1 (issue #444)

Branch: `feature/issue-444-server-initiated-close-target`
Commit reviewed: `d2bc26a` — `test(e2e): select the test's own stream connection in ServerInitiatedCloseTest (closes #444)`
Change scope: test-only (`tests/E2E/ServerInitiatedCloseTest.php`) + proof files.

## 1. Prior findings

`docs/proof_of_work/444-server-initiated-close-target/findings-review.md` did not
exist before this round, so there were no review findings from an earlier round
to re-check. The coder's own out-of-scope notes (`findings-coder.md`) are
addressed in section 4 below.

## 2. What was checked

- Full diff of `tests/E2E/ServerInitiatedCloseTest.php` against `main`.
- `E2ETestCase::createConnection()` / `setUpBeforeClass()` for host/port and
  management-port handling (lines 19-26, 28-40 of `E2ETestCase.php`).
- Tags `e2e`, `gh`, `conventions`, `naming` from `docs/helpers/faq.md` and
  `docs/helpers/decisions.md` (FAQ-001..004, DEC-001/002/004). Nothing in those
  entries contradicts the change; FAQ-001 confirms the management API + stream
  port setup the test relies on.
- Logic of the snapshot/set-difference approach, retry/sleep semantics,
  `curlGet()` failure modes, type annotations, assertion/cleanup behaviour.
- `phpunit.xml`: the file is in the `e2e` suite only; `unit` does not include
  `tests/E2E`, so unit coverage is unaffected.
- No other callers of `getStreamConnectionName()` /
  `getStreamConnectionNames()` exist.

## 3. Commands and exact results

| Command | Result |
|---|---|
| `composer cs` | exit 0 — 277/277 files, no violations (output printed only progress dots) |
| `composer phpstan` | exit 0 — `271/271`, `[OK] No errors` |
| `composer rector` | exit 0 — `[OK] Rector is done!` |
| `./vendor/bin/phpunit --testsuite unit` | exit 0 — `OK (1152 tests, 8569 assertions)`, PHP 8.5.10 |

E2E was not run here (already run by the coder: full suite OK, 146 tests).

Note: runner PHP is 8.5.10, so the `declare(strict_types=1)` + `?string` return
path is exercised under strict types; a non-string return would be a hard
`TypeError`.

## 4. Assessment of the change

The core fix is correct and matches the diagnosis in `code-decision-1.md`:

- `port` in `/api/connections` is the broker-side port (constant `5552` for
  every stream connection); the client ephemeral port is `peer_port`. The old
  predicate therefore matched *any* stream connection. It is now gone.
- The snapshot is taken in `setUp()` (line 39) **before** `createConnection()`
  (line 41), so the test's own connection is the newly-appeared name in the
  poll loop. Ordering is correct.
- Retry semantics are preserved. Old loop slept on every path that did not
  return; the new loop does one management GET per iteration and `sleep(1)` when
  no new name is seen (line 116). `curlGet() === null` / non-array JSON now
  surface as `[]` from the helper, which still results in a sleep and another
  attempt — no busy loop, no missing sleep, one GET per poll.
- Assertions are unchanged and still correct: `ConnectionException` must be
  thrown within 10 attempts (lines 84-98) and `isConnected()` must be false
  (line 101).
- `tearDown()` cleanup is unchanged and still valid (lines 46-64).

All findings below are low/nit and non-blocking; none is a regression relative
to the pre-fix behaviour. See `findings-review.md` for the per-finding record.

### 4.1 Empty snapshot on transient API failure — low

`getStreamConnectionNames()` returns `[]` for two different situations: the
management API genuinely reports no stream connections, and the GET failed
(`curlGet() === null`, line 142-144) or returned non-array JSON (line 146-149).
If the GET fails exactly in `setUp()`, the snapshot is empty and the poll loop
falls back to selecting the first stream connection seen — i.e. the original bug
returns. Reachability requires *both* a transient management-API failure at that
instant *and* a pre-existing unrelated stream connection. On the CI path
(`run-e2e.sh` boots a fresh broker, health-checked before the suite; a single
suite runs) there are no unrelated stream connections, so an empty snapshot is
still correct. On a shared/dev broker with a leftover/squatter connection it
becomes a flake. A cheap hardening would be to retry the snapshot GET a few
times in `setUp()` or distinguish "failure" from "empty" and fail fast. Not a
blocker.

### 4.2 Concurrency between two E2E runs — low / accepted, recorded

Already documented in `code-decision-1.md` ("Uncertainties") and
`findings-coder.md` (out-of-scope #1). Two runs against one broker can each
select the other's newly-appeared connection. The correct long-term fix is a
per-connection `connection_name` peer property, which needs a production API
change the issue explicitly forbids ("test-only"). CI runs a single suite against
a fresh broker, so this does not occur today. Out of scope and recorded.

### 4.3 Numeric-string connection names coerce to int keys — nit

At line 172 (`$names[$conn['name']] = true;`) PHP converts a purely numeric
string key to `int`. `array_keys()` at line 110 would then yield an `int`, and
`getStreamConnectionName(): ?string` would throw a `TypeError` under
`strict_types` when returning it. Real RabbitMQ stream connection names are of
the form `"<ip>:<port> -> <ip>:5552"`, so this is unreachable in practice. PHPStan
level 9 does not flag it (the docblock type is trusted; the coercion is runtime
behaviour). Optional hardening: build a `list<string>` and use
`in_array($name, $existing, true)`, or `array_values()` before returning.

### 4.4 Deterministic repro not pinned by a committed test — low / optional

The deterministic scenario (an unrelated idle stream connection must not be
force-closed) was verified manually with a throwaway "squatter" connection (see
`findings-coder.md`), not committed. It could be pinned: have `setUp()` open a
second, kept-alive decoy `Connection` *before* the snapshot, then the new code
excludes it (present in snapshot) while the old `port` predicate would have
selected the decoy and the test would fail. That is a genuine regression guard,
but it adds a heartbeat-issuing helper and complexity to an already
broker-dependent E2E test. Worth considering, not required for this fix.

### 4.5 CHANGELOG not yet updated — expected, not a code issue

No `444` entry in `CHANGELOG.md`/`README.md`. As stated in the task, this is
expected to be handled at merge time and is not flagged as a code problem.

### 4.6 Duplicated management-API parsing — nit, pre-existing

The `is_array`/`isset`/`is_string` field validation for `/api/connections` is
duplicated across E2E tests (and was duplicated before this change). A shared
typed helper would reduce it. Pre-existing, out of scope for #444.

## 5. Verdict

Code looks good, no issues to fix. The change correctly identifies the test's
own stream connection via a `setUp()` snapshot + set difference, removes the
invalid `port` predicate, preserves retry/assertion/cleanup semantics, and passes
PHPCS, PHPStan level 9, Rector and the unit suite. The low/nit observations above
are recorded in `findings-review.md` as non-blocking.
