# Review 1 — #476 test-exclusion guard

Branch `process/issue-476-test-exclusion-guard`, HEAD `9481557`, diff vs `main`.
Reviewer is read-only; this file plus `findings-review.md` are the only writes.

## Scope reviewed

- `bin/check-test-suites.php` (new, 178 lines)
- `phpunit.xml` (+2 allow-list entries)
- `composer.json` (`test:suite-coverage` script, added to `lint`)
- `.github/workflows/ci.yml` (new step in the `lint` job)
- `bin/README.md`, `AGENTS.md`, `docs/workflow.md`, `CHANGELOG.md`
- `docs/proof_of_work/476-test-exclusion-guard/{code-decision-1.md,findings-coder.md}`

This is a process/CI change, not protocol wire-format code, so `review`
(not `review-critical`) is the correct level.

## Verdict

The gate is correct for the repository's actual configuration, and every claim
in `findings-coder.md` that I re-ran reproduced. No `high` finding. One `medium`
is **pre-existing and out of scope** (coder finding #1). The remaining findings
are `low`/`nit` latent-robustness and documentation items. I see **no blocker**
for this PR.

## Independent verification (all commands run on this branch)

### The gate itself

| Scenario | Command | Result |
| --- | --- | --- |
| pristine tree | `php bin/check-test-suites.php` | `Test suite coverage OK: 148 test file(s) all covered by 15 allow-list entries.` exit `0` |
| uncovered file | `mkdir tests/TmpReviewDir && printf '<?php\n' > tests/TmpReviewDir/BarTest.php`, run gate | lists `tests/TmpReviewDir/BarTest.php`, `1 test file(s) would never run in CI`, exit `1`; temp dir removed |
| stale entry | temp config with `<directory>tests/DoesNotExist</directory>` replacing `tests/VO` | reports the 9 now-uncovered `tests/VO/*Test.php` **and** `tests/DoesNotExist ... stale`, exit `1` |
| malformed XML | `<phpunit><testsuites>` | `could not parse`, exit `2` |
| missing config | `/tmp/nope-xyz.xml` | `phpunit config not found`, usage, exit `2` |
| composer alias | `composer run test:suite-coverage` | same OK line, exit `0` |

The uncovered-file and stale-entry behaviours the coder claims are real and
reproduce exactly. `find tests -name '*Test.php' | wc -l` = **148**, matching the
gate's reported discovery count.

### Parsing correctness (DOM/XPath, recursion, directory/file)

- `/phpunit/testsuites/testsuite` + relative `query('directory|file', $suite)`
  returns exactly the 14 `unit` entries and the 1 `e2e` entry — I dumped this
  independently and it matches `phpunit.xml:7-25`. Namespace on `<phpunit>`
  is `xsi` only, so the un-namespaced XPath is correct.
- `<directory>` matching is boundary-safe: `str_starts_with($file, $dir . '/')`
  with `rtrim`, plus exact `$file === $dir`; a sibling like `tests/VOExtended`
  will not be matched by `tests/VO`. `<file>` requires exact equality.
- `<file>tests/PlatformTest.php</file>` vs discovered `tests/PlatformTest.php`
  matches (verified by the pristine pass).
- Recursion uses `RecursiveIteratorIterator` + `SKIP_DOTS`; `getFilename()`
  suffix test ignores `E2ETestCase.php` and `tests/Support/*` correctly.
- `tests/E2E` is covered by the `e2e` `<directory>`, and the gate checks the
  **union of all suites** (per `$isCovered`), which is the right reading of the
  issue ("neither unit nor e2e"). Adding `tests/E2E/FooTest.php` outside the E2E
  dir but under no listed dir would fail — correct.

### `phpunit.xml` change

- `tests/PlatformTest.php` (2 tests) and `tests/Exception/ExceptionHierarchyTest.php`
  (5 tests) → **7 tests / 22 assertions**, all green:
  `./vendor/bin/phpunit tests/PlatformTest.php tests/Exception/ExceptionHierarchyTest.php`
  `OK (7 tests, 22 assertions)`.
- Both are pure reflection/`Platform` tests, no broker; `unit` is the right
  suite (not `e2e`). This **adds coverage**; it does not weaken the gate.
- Unit suite: `./vendor/bin/phpunit --testsuite unit` → `OK (1234 tests, 8779 assertions)`.
  Coder wrote 8775 assertions (findings-coder.md:72); actual is **8779**.
  Test *count* 1234 is right and is the number that matters. NIT-1 below.

### Wiring

- `composer lint` now ends with `php bin/check-test-suites.php` and prints the
  coverage OK line → **verified**, `composer lint` exit `0`
  (phpcs, rector, phpstan L9, kb-lint, docs-links, suite-coverage all ran).
- `.github/workflows/ci.yml:94-95` adds `Check test suite coverage` /
  `composer test:suite-coverage` to the `lint` job → verified in the diff and file.
- The required check context is `name:` at `ci.yml:69`. It is **unchanged** —
  the diff only inserts steps. The critical byte-identical requirement is met.
  Branch protection context `lint` stays reachable.
- Pre-push hook runs `composer lint` (`bin/hooks/pre-push`), so the gate is
  enforced locally too.

### Coder finding #1 — CI `lint` omits `kb-lint` + `check-docs-links`

**Confirmed real.** `ci.yml:85-95` runs `composer cs`, `composer rector`,
`composer phpstan`, `composer test:suite-coverage`. `composer.json:34` `lint`
additionally runs `php bin/kb-lint.php` and `php bin/check-docs-links.php`.
`grep -rn 'kb-lint\|check-docs-links' .github/` returns **no match** — neither
runs anywhere in CI. The pre-push hook comment ("CI runs the same checks") is
therefore false. This is pre-existing (the diff only *adds* a step), so it is
out of scope for #476; the fix belongs in a follow-up. See R1-1.

### Coder finding #2 — `bin/` outside PHPCS/PHPStan

**Confirmed real.** `phpcs.xml.dist:6-8` lists only `src`, `tests`, `examples`;
`phpstan.neon` paths are only `src`, `tests`. `bin/*.php` (including the new
gate) is neither style-checked nor type-checked. Pre-existing, low. See R1-2.

## Findings introduced in this review

- **R1-3 (low, doc):** `bin/check-test-suites.php:22` says "`2` usage error",
  but the script also exits `2` for a missing/unparseable config, an empty
  allow-list, and a missing `tests/` dir (`:33-37`, `:44-47`, `:52-55`, `:102-105`).
  `bin/README.md` already says "`2` usage/config error" — the file-level docblock
  should match. An automated check cannot catch this (prose); a one-word fix.
- **R1-4 (low, latent robustness):** `bin/check-test-suites.php:30,101,133`
  resolve the allow-list and discovery against `dirname(__DIR__)` (repo root),
  while the documented usage (`:21`, `bin/README.md`) allows an arbitrary config
  path. If the config lived outside the repo, `<directory>` paths would resolve
  against the repo root, not the config's directory, and there is no
  `phpunit.xml.dist` fallback (`:33-37` exits `2` if `phpunit.xml` is absent).
  Not triggered here — `phpunit.xml` is tracked (`git ls-files` confirms) and
  lives at the root — but the alternate invocation is only partially supported.
  Cheap hardening: resolve relative paths against `dirname($configPath)` and/or
  note the root-relative assumption in the docblock.

## Nits

- **NIT-1:** `findings-coder.md:72` records "8775 assertions"; the run reports
  8779. Environment/PHP-version drift, not a code defect. No action needed.
- **NIT-2:** the gate does not honour `<directory suffix="...">`/`prefix`
  attributes; the script docblock (`:24-27`) states this explicitly and the
  current config uses neither. Acceptable; if a future suite adds `suffix`,
  a false *negative* (file considered covered but PHPUnit skips it) appears.
  Worth a knowledge-base note at most.

## Checks run

```
php bin/check-test-suites.php                       # 0
composer lint                                       # 0
composer run test:suite-coverage                    # 0
./vendor/bin/phpunit --testsuite unit               # OK 1234 tests, 8779 assertions
./vendor/bin/phpunit tests/PlatformTest.php \
    tests/Exception/ExceptionHierarchyTest.php      # OK 7 tests, 22 assertions
```

No E2E run (not a wire-level change; `tests/E2E` is not touched).
