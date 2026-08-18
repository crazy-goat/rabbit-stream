# Findings — Coder (issue #459)

## Obstacles / surprises

1. **1 Risky test in the full unit suite** — `tests/StreamConnectionTest.php:567`
   (`testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`) is flagged "risky"
   (executes but performs no assertions). This is **pre-existing** — the file
   was already in the `unit` allow-list before this change — and it is already
   tracked as open issue **#454**. Not a regression from #459; no action taken
   here.

2. **`docs/workflow.md` had an uncommitted modification** in the working tree
   when the branch was created. It is not part of issue #459 and was left out
   of the commit (only `phpunit.xml` + PoW files staged).

## Out-of-scope bugs / places to improve

1. **`phpunit.xml:6-13` — the allow-list pattern is fragile by design.**
   Any future test file added at the top level of `tests/` (or a new
   subdirectory) silently stays out of CI unless `phpunit.xml` is updated.
   This exact failure produced #459. Suggested fix: add a CI check (or a small
   script/bin) that fails when a `tests/**/*Test.php` file exists outside the
   allow-list, or add a comment block in `phpunit.xml` listing the current
   coverage and a reminder to add new directories. A `composer` script that
   diffs discovered vs. configured tests would have caught #459 automatically.

2. **`tests/StreamConnectionTest.php:567` — the risky test also has no name
   guard on what it asserts.** It only exercises the socket pair; a future
   refactor could make the test pass vacuously. Tracked as #454; adding a
   single `assertTrue(true)`-style assertion would clear the "risky" flag, but
   a real assertion on the callback-not-registered path (e.g. no exception
   thrown + connection still readable) is preferable.

3. **`phpunit.xml` has no `<coverage>` report configuration** — consistent
   with the project's documented "no coverage gate" decision, but worth noting
   that adding these 182 tests to the suite will shift coverage numbers if a
   local coverage run is ever used for decisions.

## What I verified

- Previously-excluded files via `--filter`: **OK (182 tests, 333 assertions)**.
- Full `--testsuite unit`: **Tests: 844, Assertions: 2786, Risky: 1** (pre-existing, #454).
- `composer cs` (PHPCS PSR-12): passed, 241 files.
- `composer phpstan` (level 9): **No errors** (237/237).
- E2E deliberately not run — no wire-level change (config-only per the issue and workflow step 3).
