# Code Decision 1 — #476 test-exclusion guard

## Problem

`phpunit.xml` enumerates the files each suite runs. PHPUnit silently ignores
any `tests/**/*Test.php` outside those entries, so CI (which runs
`--testsuite unit` / `e2e`) goes green while the file is never executed. #459
was the first occurrence (six files, 182 tests). #476 asks for a gate so the
next occurrence fails the build instead of shipping quietly.

## Approach

`bin/check-test-suites.php` (new), plus wiring:

- Parse `phpunit.xml` with `DOMDocument`/`DOMXPath` (already a hard dependency
  of PHPUnit) — **do not hard-code paths**. Every suite's `<directory>`/`<file>`
  entries form the allow-list.
- Discover `tests/**/*Test.php` on disk recursively with
  `RecursiveDirectoryIterator`.
- Fail if a discovered file is covered by no entry (a `<file>` matches
  exactly, a `<directory>` matches itself and any descendant — PHPUnit
  `<directory>` discovery is recursive).
- Fail the reverse direction too: an allow-list entry whose path no longer
  exists. Cheap, and it catches a directory rename leaving a suite pointing at
  nothing.
- Exit codes mirror `bin/check-docs-links.php`: `0` clean, `1` failure,
  `2` usage/config error. No `--fix`, no `--json` — nothing here is fixable
  automatically, and one output format is enough.

Wiring:

- `composer.json`: new `"test:suite-coverage"` script, referenced as
  `@test:suite-coverage` from `lint` (so the pre-push hook runs it too).
- `.github/workflows/ci.yml`: explicit step in the `lint` job. The job runs
  `cs`/`rector`/`phpstan` directly rather than `composer lint`, so a composer
  script alone would not reach CI.
- Docs: `bin/README.md` (new section), `docs/workflow.md` (lint contents in
  §7 and §10), `AGENTS.md` QA Commands, `CHANGELOG.md` `[Unreleased]` → Added.

## Surprise that changed the change

Running the new gate against the pristine tree **failed**: two files were
already outside every suite —

- `tests/PlatformTest.php`
- `tests/Exception/ExceptionHierarchyTest.php`

both added after #459 fixed the original six. This is exactly the issue's
premise, live. They were added to the `unit` allow-list (`tests/Exception`
directory + `tests/PlatformTest.php` file) so the gate passes on a complete
tree. That is coverage being *added*, not the gate being weakened. Unit tests
went from 1227 to 1234 (the 7 tests in those two files).

## Rejected alternatives

- **Copy `find tests -name '*Test.php'` + shell diff against a hard-coded
  path list.** Rejected: the hard-coded list drifts from `phpunit.xml`, which
  is the very staleness the gate is meant to detect. Parsing the config keeps
  one source of truth.
- **`phpunit --list-tests` and diff against `find`.** Would also require
  running PHPUnit and mapping class names back to files; parsing the config is
  deterministic and needs no bootstrap. (`--list-tests` output is test-case
  names, not the files that were not loaded, so it does not directly expose
  the exclusions anyway.)
- **Shell script in `bin/`.** The existing repo-integrity gates
  (`kb-lint.php`, `check-docs-links.php`) are PHP; matching that keeps error
  reporting and exit codes consistent.
- **Only the forward check.** The reverse check is one `is_file`/`is_dir` per
  entry and closes the stale-path class; kept.

## Uncertainties

- The script assumes the repo convention `*Test.php` and plain
  `<directory>`/`<file>` entries (no `suffix`/`prefix` attributes). Stated in
  the script docblock. If a future suite uses those attributes, the discovery
  step would need to honour them; the current config does not.
- `tests/Support/` holds helpers, not `*Test.php`, so it is correctly ignored.
- Should the reverse check be an error (exit 1) or a warning (exit 0)? Chosen
  error: a listed path that does not exist is unambiguously a bug in the
  config, and #476 is explicitly an "adds a gate" change.
