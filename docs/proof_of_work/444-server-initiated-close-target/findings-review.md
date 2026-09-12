# Findings Review — issue #444

Format: `file:line | description | severity | status`.

No `findings-review.md` existed before round 1, so there are no earlier review
findings to re-check. Entries below are what round 1 added. All are non-blocking.

---

1. `tests/E2E/ServerInitiatedCloseTest.php:39,142-149` | Empty snapshot on
   transient management-API failure: `getStreamConnectionNames()` returns `[]`
   both when there are genuinely no stream connections and when `curlGet()`
   returns `null` / JSON is non-array. A failure exactly in `setUp()` makes the
   snapshot empty and the poll loop then selects the first stream connection it
   sees, which reintroduces the original bug if an unrelated connection exists.
   Reachable only with a transient API failure plus a pre-existing unrelated
   connection; not reachable on the CI path (fresh broker, single suite). |
   **low** | recorded, not fixed — optional hardening: retry the snapshot GET in
   `setUp()` or distinguish failure from empty and fail fast. Automated check
   that did not catch it: PHPStan (behavioural, not type).

2. `tests/E2E/ServerInitiatedCloseTest.php:104-134` | Concurrency: two E2E runs
   against one broker can each select the other run's newly-appeared connection
   and force-close it, leaving their own connection healthy and flaking the
   assertion. Already documented in `code-decision-1.md` and
   `findings-coder.md`. The robust fix (`connection_name` peer property) needs a
   production API change forbidden by the issue's test-only constraint. |
   **low** | accepted / recorded — out of scope; CI runs a single suite on a
   fresh broker.

3. `tests/E2E/ServerInitiatedCloseTest.php:172,110` | Numeric-string connection
   names are coerced to `int` array keys by PHP, so `array_keys()` yields an
   `int` and `getStreamConnectionName(): ?string` would throw a `TypeError`
   under `strict_types`. Real RabbitMQ stream connection names always contain
   non-numeric characters (`<ip>:<port> -> <ip>:5552`), so unreachable in
   practice. | **nit** | recorded, not fixed — optional hardening: return a
   `list<string>` and match with `in_array(..., true)`, or `array_values()`.
   Automated check that did not catch it: PHPStan level 9 passed (docblock type
   is trusted; key coercion is runtime behaviour).

4. `tests/E2E/ServerInitiatedCloseTest.php:35-44` | The deterministic repro (an
   unrelated idle stream connection must not be force-closed) was verified only
   with a manual throwaway squatter (`findings-coder.md`), not pinned by a
   committed test. It could be pinned by opening a kept-alive decoy connection
   before the snapshot, which the old `port` predicate would have selected. |
   **low** | recorded, not fixed — optional regression guard; adds a
   heartbeat-issuing helper to an already broker-dependent E2E test.

5. `tests/E2E/ServerInitiatedCloseTest.php:136-176` | Duplicated
   management-API field validation (`is_array`/`isset`/`is_string`) across E2E
   tests; a shared typed helper would remove the repetition. Pre-existing. |
   **nit** | recorded, not fixed — out of scope for #444.

6. `CHANGELOG.md` | No `[Unreleased]` entry for #444 yet. Expected at merge
   time, not a code issue. | **nit** | noted only, no action.

---

## Summary

| # | Severity | Status |
|---|---|---|
| 1 | low | recorded, optional |
| 2 | low | accepted, recorded |
| 3 | nit | recorded, optional |
| 4 | low | recorded, optional |
| 5 | nit | recorded, out of scope |
| 6 | nit | noted |

No high/medium findings. No finding blocks the merge.
