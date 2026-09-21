# Findings (coder) — #476 test-exclusion guard

## Obstacles / surprises

1. **The gate fails on the pristine tree — two files were already silently
   excluded.** `tests/PlatformTest.php` and
   `tests/Exception/ExceptionHierarchyTest.php` sat outside every
   `phpunit.xml` `<testsuite>`. Both were added *after* #459 landed, i.e. the
   regression #476 is written to prevent had already recurred once. Added to
   the `unit` allow-list in this PR; `--testsuite unit` grew 1227 → 1234 tests
   (7 tests / 22 assertions from the two files, all passing).
   - `tests/PlatformTest.php` — 2 tests, pure `Platform`/exception-hierarchy
     reflection, no broker.
   - `tests/Exception/ExceptionHierarchyTest.php` — 5 tests, reflection over
     `src/Exception/`, no broker.
   Both are unit-safe; putting them in `e2e` would have been wrong.

2. **`bin/` is invisible to the existing static gates.** PHPCS
   (`phpcs.xml.dist` scans `src`, `tests`, `examples`) and PHPStan
   (`phpstan.neon` paths `src`, `tests`) do not include `bin/`. So the new
   `bin/check-test-suites.php` is neither style-checked nor type-checked. Not
   a correctness problem here (the script is run and exercised), but it means
   a typo class in `bin/` is not caught by CI. See finding (1) below.

## Discovered bugs / places to improve (incl. out of scope)

1. **CI `lint` job does not run all of `composer lint` — `kb-lint` and
   `check-docs-links` are missing.**
   - `.github/workflows/ci.yml:85-92` runs `composer cs`, `composer rector`,
     `composer phpstan` as three separate steps and nothing else. `composer
     lint` (composer.json:34) additionally runs `php bin/kb-lint.php` and
     `php bin/check-docs-links.php`, but those two are never invoked in CI.
   - Impact: a PR can break the `docs/helpers/` tag index or a relative link
     under `docs/en/` and still pass the required `lint` check; the breakage
     only surfaces locally via the pre-push hook (which a contributor may not
     have installed). The new `test:suite-coverage` step was added explicitly
     for exactly this reason, but the existing two were left behind.
   - Suggested fix: replace the three bespoke steps with a single
     `run: composer lint` step (keeping the job `name: lint` byte-identical,
     per the comment at `.github/workflows/ci.yml:8-14`), or add explicit
     `php bin/kb-lint.php` / `php bin/check-docs-links.php` steps. Prefer
     `composer lint` so CI and the pre-push hook can never diverge again.
   - Severity: medium (a documented gate does not actually run in CI).

2. **`bin/` static-analysis blind spot** (same root as obstacle 2). PHPStan
   level 9 and PHPCS PSR-12 cover `src`/`tests`/`examples` only. The new
   script is plain enough that this does not bite today, but `bin/kb-lint.php`
   and `bin/check-docs-links.php` have lived under the same blind spot.
   - Suggested fix (optional): add `bin` to `phpstan.neon` `paths` and to
     `phpcs.xml.dist` `<file>` entries, then fix whatever they report. Filed
     as an observation, not fixed here — it is broader than #476 and could
     surface unrelated noise.
   - Severity: low.

## Verification run

```
php bin/check-test-suites.php
  → Test suite coverage OK: 148 test file(s) all covered by 15 allow-list entries. (exit 0)

mkdir -p tests/SomeDir && touch tests/SomeDir/FooTest.php
php bin/check-test-suites.php
  → Test files not covered by any phpunit.xml testsuite:
      - tests/SomeDir/FooTest.php
    ... 1 test file(s) would never run in CI. (exit 1)
  → removed again

# stale entry (temporary config copy with <directory>tests/DoesNotExist</directory>)
php bin/check-test-suites.php /tmp/phpunit-copy.xml
  → phpunit.xml allow-list entries that do not exist on disk: tests/DoesNotExist (exit 1)

./vendor/bin/phpunit --testsuite unit  → OK (1234 tests, 8779 assertions)
composer lint                          → OK (phpcs, rector, phpstan L9, kb-lint, docs links, suite coverage)
```

> Correction (round-1 review NIT): the assertion count above is **8779**, not
> 8775. The 1234 test count was correct. Round-1 verification adds
> `tests/Util/CheckTestSuitesScriptTest.php` (3 tests), so the final branch runs
> **1237 tests / 8782 assertions**.

## Candidate knowledge-base entries (for the retro; not written here)

- **Proposal:** "A PHPUnit `<testsuite>` allow-list silently drops files
  outside it; gate the union against `find tests -name '*Test.php'`."
  - tags: `ci`, `phpunit`, `tests`, `gates`
  - trigger: "when adding or moving a `tests/**/*Test.php` file, or editing
    `phpunit.xml` testsuites"
  - gate: `bin/check-test-suites.php` (composer `test:suite-coverage`).
  Per "prefer a gate over an entry", this should probably be `promoted` with
  the gate named rather than a full entry.
