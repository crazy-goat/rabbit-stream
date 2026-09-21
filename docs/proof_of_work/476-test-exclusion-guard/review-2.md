# Review 2 (convergence) — #476 test-exclusion guard

Branch `process/issue-476-test-exclusion-guard`, HEAD `04a1d6f`, diff vs `main`.
Reviewer is read-only w.r.t. source; this file plus the round-2 append to
`findings-review.md` are the only writes.

## Verdict

Converged. Every round-1 finding was re-verified against the round-2 tree.
The two functional fixes (R1-3, R1-4) are present and independently reproduce;
the two pre-existing items (R1-1, R1-2) are correctly scoped as out-of-scope
follow-ups; the nit is corrected. The new verification test is real and is
itself inside the unit allow-list. **No new high/medium/low finding.** No
blocker. Nothing to commit from this reviewer.

## Round-1 finding verification

### R1-3 — exit-code docblock (low, doc) — **FIXED, verified**

`bin/check-test-suites.php:22` now reads
`Exit codes: 0 clean, 1 uncovered/stale paths, 2 usage/config error.`
The implementation's exit-2 sources are usage (missing arg/config, `:60-65`)
and config (unparseable `:71-74`, empty allow-list `:105-108`, missing
`tests/` `:133-136`). Docblock, `bin/README.md:86-87` and behaviour all agree.
Verified by the malformed/missing-config paths below.

### R1-4 — config resolution (low, latent) — **FIXED, verified**

| Requirement | Evidence | Result |
| --- | --- | --- |
| `.xml` preferred over `.dist` | temp dir with valid `.dist` **and** empty-allow-list `.xml`; gate exits `2` naming `phpunit.xml` (had `.dist` won it would exit `0`) | ✅ |
| directory argument | temp dir with only `phpunit.xml.dist` + `tests/Foo/BarTest.php`, invoked as `php bin/check-test-suites.php <dir>` → `1 test file(s) all covered`, exit `0` | ✅ |
| config-relative resolution | logic `:68` `$configDir=dirname(realpath)`, `:132` discovery root, `:151` path stripping, `:164` stale base — all use `$configDir`; test 3 drives a config outside the repo | ✅ |
| cwd independence | `cd /tmp && php /…/bin/check-test-suites.php` → same `149 … / 15 …`, exit `0` | ✅ |

**New test genuinely covers it.** `tests/Util/CheckTestSuitesScriptTest.php`
shells out via `proc_open` to the real script against temp trees. Each of the
three cases discriminates against the old behaviour: test 1 and 2 would have
exited `2` under the old single-`phpunit.xml` lookup; test 3's `BazTest.php`
would never be discovered under the old repo-root base. Standalone run:
`OK (3 tests, 7 assertions)`. Note the test's `makeTempDir` calls `realpath`,
so the "outside the repo" config dir is canonical before the gate resolves it.

**No blind spot for the test itself.** `phpunit.xml:17` lists
`<directory>tests/Util</directory>`; the new file is
`tests/Util/CheckTestSuitesScriptTest.php` (suffix `Test.php`), so it is inside
the allow-list and runs in the unit suite (confirmed: unit count 1234 → 1237).
`find tests -name '*Test.php' | wc -l` = **149**, exactly the gate's reported
discovery count. The gate does not exempt itself.

### R1-1 — CI `lint` omits `kb-lint`/`check-docs-links` (medium, pre-existing) — **answered, out of scope**

Re-confirmed pre-existing: `ci.yml:85-95` runs `cs`/`rector`/`phpstan`/
`test:suite-coverage`; `composer.json:34` also runs `php bin/kb-lint.php` and
`php bin/check-docs-links.php`; `grep -rn 'kb-lint|check-docs-links' .github/`
→ **no match**. The #476 gate **does** run in CI at `ci.yml:94-95`
(`composer test:suite-coverage`). Ownership of the gap is documented as a
follow-up (single `run: composer lint`, keeping job `name: lint` byte-identical).
Not a regression and not introduced by this diff.

### R1-2 — `bin/` outside PHPCS/PHPStan (low, pre-existing) — **answered, out of scope**

Re-confirmed: `phpcs.xml.dist` files are `src`, `tests`, `examples`;
`phpstan.neon` paths are `src`, `tests`. No `bin/`. Pre-existing; the new gate
is nevertheless exercised end-to-end by the unit test above. Documented as a
follow-up.

### R1-NIT — stale assertion count — **corrected, verified (with a caveat)**

`findings-coder.md:72-79` now records 8779 and notes the branch is
`1237 tests / 8782 assertions`. The 8775→8779 correction is right: the branch
at the prior head measured `1234 tests / 8779 assertions` (re-run here with the
new test filtered out: `OK (1234 tests, 8779 assertions)`).

**Caveat (informational nit, pre-existing):** the assertion total is *not
deterministic*. Four consecutive `./vendor/bin/phpunit --testsuite unit` runs
gave `8782, 8786, 8786, 8782`. JUnit-log diffing isolates the cause to a single
pre-existing, unrelated test:
`CrazyGoat\RabbitStream\Tests\Client\ConsumerTest::testReadWaitsThroughResubscribeBackoffAfterLostSubscription`
(143 vs 147 assertions). So "8782" is one valid sample, not a fixed value.
Both 8782 and 8786 round-trip with 1237 tests; the *test count* is stable and
correct. No action for #476 (the flaky test is untouched by this diff).

## New-issue sweep (`git diff main...HEAD`)

Reviewed the whole diff: `bin/check-test-suites.php` (new), `tests/Util/
CheckTestSuitesScriptTest.php` (new), `phpunit.xml`, `composer.json`,
`.github/workflows/ci.yml`, `bin/README.md`, `AGENTS.md`, `docs/workflow.md`,
`CHANGELOG.md`, the four PoW records. The allow-list union semantics, boundary
match (`str_starts_with($file, $dir . '/')`, exact `$file === $dir`), `<file>`
exact match, `./`-prefix stripping, `suffix`/`prefix` absence, and the stale
reverse-check are all correct for this repository's config (nothing changed
there from round 1).

- **N2-1 (informational nit):** `$dir = rtrim($entry['path'], '/')` makes
  `<directory>/</directory>` reduce to `''`, and `:125` then treats `''` as
  "covers everything". Pathological config; not present here.
- **N2-2 (informational nit):** absolute `<directory>`/`<file>` entries (legal
  in PHPUnit) are treated as config-relative; the stale check builds
  `$configDir . '/' . '/abs'` and discovered paths are relative, so an absolute
  entry would neither be reported stale nor cover anything. Not present here;
  the current config is entirely relative. The docblock's "Assumes …" list
  could say "relative entries" explicitly.
- **N2-3 (informational nit):** the pre-existing nondeterministic assertion
  count described under R1-NIT. Out of scope.

None of these is high/medium/low for this PR.

## Independent re-run of the failure proof

```
# uncovered file
mkdir -p tests/TmpReviewRound2 && printf '<?php\n' > tests/TmpReviewRound2/BarTest.php
php bin/check-test-suites.php
  → "Test files not covered by any phpunit.xml testsuite: tests/TmpReviewRound2/BarTest.php"
  → "1 test file(s) would never run in CI." exit 1
rm -rf tests/TmpReviewRound2
php bin/check-test-suites.php → "Test suite coverage OK: 149 test file(s) … ", exit 0
```
No artifacts left (`git status --porcelain` clean).

## Checks run

```
php bin/check-test-suites.php                 # exit 0, 149 files / 15 entries
composer lint                                 # exit 0 (phpcs, rector, phpstan L9,
                                              #   kb-lint, docs-links, suite-coverage)
./vendor/bin/phpunit --testsuite unit         # OK 1237 tests, 8782/8786 assertions
./vendor/bin/phpunit tests/Util/CheckTestSuitesScriptTest.php   # OK 3 tests, 7 assertions
cd /tmp && php <repo>/bin/check-test-suites.php                 # exit 0
```

No E2E run: wire-level code untouched, `tests/E2E` unchanged.

## Open findings

- New high/medium/low introduced by this PR: **none**.
- Remaining open items are the two accepted **pre-existing, out-of-scope
  follow-ups**: R1-1 (medium, CI `lint` omits `kb-lint`/`check-docs-links`) and
  R1-2 (low, `bin/` outside PHPCS/PHPStan). Neither blocks #476.
- All other round-1 findings are resolved; the new-issue sweep yields only
  informational nits (N2-1…N2-3).
