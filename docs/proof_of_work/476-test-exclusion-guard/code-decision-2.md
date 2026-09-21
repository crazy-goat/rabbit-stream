# Code Decision 2 — #476 test-exclusion guard (review round 1)

Round-1 review (`review-1.md`) found no blocker: one pre-existing `medium`
(R1-1, out of scope) and `low`/`nit` documentation and robustness items. This
decision record covers the fixes made in response.

## R1-3 — exit-code docblock (low, doc)

`bin/check-test-suites.php:22` said "`2` usage error", while the script also
exits `2` for a missing/unparseable config, an empty allow-list, and a missing
`tests/` directory. `bin/README.md` already said "`2` usage/config error".

**Decision:** align the docblock with the implementation and the README —
"`2` usage/config error". Documentation-only; no behaviour change, no gate
weakening.

## R1-4 — config resolution and `.dist` fallback (low, latent robustness)

Previously `$root = dirname(__DIR__)` was used to load the config, discover
`tests/`, and resolve every `<directory>`/`<file>` entry, and `phpunit.xml` was
the only accepted default.

**Decision:** make resolution match PHPUnit's own precedence and the config's
location:

1. **Fallback:** with no argument, look for `phpunit.xml`, then
   `phpunit.xml.dist`. PHPUnit's documented precedence is `.xml` over `.dist`,
   so this only widens support (a tree that ships only `.dist` now checks).
2. **Explicit argument:** a file is used as-is; a **directory** is searched for
   the same `phpunit.xml`/`phpunit.xml.dist` pair. This makes the fallback
   reachable and testable from a temporary tree.
3. **Path base:** the config is `realpath`-ed and `$configDir =
   dirname($configPath)` becomes the base for relative allow-list entries *and*
   the `tests/` discovery root. A config outside the repo no longer resolves its
   relative paths against the repo root.
4. **Working directory:** the default resolution still starts from
   `dirname(__DIR__)` (the script's parent), so invoking the gate from any cwd
   behaves identically. This is now stated explicitly in the docblock and
   `bin/README.md`.

This cannot weaken the gate: the repository-root config still resolves to the
same base, and the default lookup still selects the tracked `phpunit.xml`.

### Verification path

`tests/Util/CheckTestSuitesScriptTest.php` (unit suite, automatically covered
by the existing `<directory>tests/Util</directory>` entry) shells out to the
gate against temp trees:

- `testFallsBackToDistWhenXmlIsAbsent` — only `phpunit.xml.dist` present, exit
  `0`.
- `testPrefersXmlOverDist` — both present, the `.dist` would fail (empty
  allow-list) but `.xml` wins, exit `0`.
- `testResolvesRelativePathsAgainstConfigDirectory` — config outside the repo;
  an uncovered file is discovered and `tests/Foo` is not reported stale, exit
  `1`.

## R1-1 — CI `lint` omits `kb-lint` / `check-docs-links` (medium, pre-existing)

Confirmed real but **pre-existing and out of scope**: `.github/workflows/ci.yml:85-95`
runs only `cs`/`rector`/`phpstan`/`test:suite-coverage`, while `composer.json:34`
`lint` also runs `php bin/kb-lint.php` and `php bin/check-docs-links.php`
(neither appears anywhere under `.github/`). Suggested follow-up: replace the
four bespoke steps with a single `run: composer lint`, keeping the job `name:
lint` byte-identical (`ci.yml:8-14`). Not fixed here to avoid scope creep.

The #476 gate itself **does** run in CI: `ci.yml:94-95` calls
`composer test:suite-coverage` → `php bin/check-test-suites.php`.

## R1-2 — `bin/` outside PHPCS/PHPStan (low, pre-existing)

Confirmed real: `phpcs.xml.dist:6-8` and `phpstan.neon` cover `src`, `tests`,
`examples` only, so `bin/*.php` is unchecked. Observation only; broader than
#476. The new gate is now exercised end-to-end by a `tests/` unit test, which
covers the behavioural surface.

## R1-NIT — assertion count

`findings-coder.md` recorded 8775; review-1 measured 8779. Corrected to 8779
with an inline note. Adding the R1-4 verification test (3 tests / 3 assertions)
moves the final branch to **1237 tests / 8782 assertions**.

## Round-2 verification

```
php bin/check-test-suites.php             # OK 149 files / 15 entries, exit 0
composer lint                             # exit 0 (phpcs, rector, phpstan L9,
                                          #   kb-lint, docs-links, suite-coverage)
./vendor/bin/phpunit --testsuite unit     # OK 1237 tests, 8782 assertions
./vendor/bin/phpunit tests/Util/CheckTestSuitesScriptTest.php
                                          # OK 3 tests, 7 assertions
```
