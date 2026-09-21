# Findings (review) — #476 test-exclusion guard

Appended across review rounds. One entry per finding: `file:line`, what is
wrong, severity, what happened to it. Nothing is deleted.

## Round 1

### R1-1 — CI `lint` job does not run all of `composer lint` (kb-lint + check-docs-links)
- **Where:** `.github/workflows/ci.yml:85-95` vs `composer.json:34`
- **What:** The `lint` job runs `composer cs`, `composer rector`,
  `composer phpstan`, `composer test:suite-coverage`. `composer lint` also runs
  `php bin/kb-lint.php` and `php bin/check-docs-links.php`; `grep` over
  `.github/` finds neither anywhere. So a PR can break the `docs/helpers/` tag
  index or a relative link under `docs/en/` and still pass the required `lint`
  context; the breakage only surfaces via the local pre-push hook, which a
  contributor may not have installed. The hook's own comment ("CI runs the same
  checks") is therefore false.
- **Severity:** medium (a documented gate does not actually run in CI).
- **Origin:** pre-existing, *not introduced by this PR* — the diff only adds the
  suite-coverage step. Coder finding #1; verified real in this round.
- **Status:** open, **accepted as out of scope for #476** (round-2 coder
  response). Confirmed pre-existing: the #476 diff only *adds*
  `.github/workflows/ci.yml:94-95`; it does not touch the missing steps. Fix
  belongs in a follow-up issue — suggested fix: replace the four bespoke steps
  (`ci.yml:85-95`) with a single `run: composer lint`, keeping the job `name:
  lint` byte-identical per `ci.yml:8-14`. **Confirming the new gate step itself
  does run in CI:** `ci.yml:94-95` invokes `composer test:suite-coverage`, which
  is `php bin/check-test-suites.php` (`composer.json`), and `composer lint`
  references the same script via `@test:suite-coverage`. So the #476 gate is
  enforced in CI; only the pre-existing `kb-lint` / `check-docs-links` gap
  remains.
- **Check that could catch it:** a CI self-test asserting that the `lint` job's
  `run:` steps equal the `composer lint` script list; or simply using
  `composer lint` as the single step. Neither exists today.

### R1-2 — `bin/` is outside PHPCS and PHPStan coverage
- **Where:** `phpcs.xml.dist:6-8` (`src`, `tests`, `examples` only),
  `phpstan.neon` (`paths: src, tests`).
- **What:** No `bin/*.php` script is style- or type-checked. This PR adds
  `bin/check-test-suites.php` into that blind spot (`bin/README.md`-documented
  behaviour is fine, but a typo-class defect in `bin/` is not gated).
- **Severity:** low.
- **Origin:** pre-existing. Coder finding #2; verified real in this round.
- **Status:** open, **accepted as out of scope for #476** (round-2 coder
  response). Pre-existing observation only, broader than #476. Fix would be
  adding `bin` to `phpstan.neon` paths / `phpcs.xml.dist` files, then clearing
  noise. Round-1 verification code in `tests/Util/CheckTestSuitesScriptTest.php`
  exercises the gate end-to-end, which partially compensates inside `tests/`.
- **Check that could catch it:** extend PHPStan/PHPCS path lists; no gate today.

### R1-3 — Exit-code docblock does not match actual exit-2 conditions
- **Where:** `bin/check-test-suites.php:22` ("`2` usage error") vs `:33-37`,
  `:44-47`, `:52-55`, `:102-105` (missing/unparseable config, empty allow-list,
  missing `tests/`).
- **What:** The file-level docblock says exit `2` is a *usage* error, but the
  script also uses `2` for *config* errors. `bin/README.md` correctly says
  "`2` usage/config error", so the two docs disagree.
- **Severity:** low (documentation only).
- **Origin:** new in this PR.
- **Status:** **fixed in round 2** — docblock now reads "`2` usage/config error"
  (matching `bin/README.md`), with exit-2 sources called out. See
  `code-decision-2.md`.
- **Check that could catch it:** none automated (prose). Manual review.

### R1-4 — Config path is honoured for loading but not for path resolution; no `.dist` fallback
- **Where:** `bin/check-test-suites.php:21,30,101,133` and `bin/README.md`
  (documents `php bin/check-test-suites.php path/to/phpunit.xml`).
- **What:** `$root = dirname(__DIR__)` is used both for discovery (`:101`) and
  to resolve `<file>`/`<directory>` entries (`:133`) and the config default
  (`:31`). An explicit config **outside** the repo would have its relative paths
  resolved against the repo root, not the config's own directory. There is also
  no fallback to `phpunit.xml.dist`; if `phpunit.xml` is absent the gate exits
  `2` rather than checking the dist file.
- **Severity:** low (latent; not triggered in this repo — `phpunit.xml` is
  tracked and lives at the repo root).
- **Origin:** new in this PR.
- **Status:** **fixed in round 2** — the gate now (a) resolves `phpunit.xml`
  then `phpunit.xml.dist` (PHPUnit precedence), (b) accepts an explicit file
  *or directory* argument resolved against the config's own directory,
  (c) realpaths the config and resolves `<directory>`/`<file>` entries and the
  `tests/` discovery root against `dirname($configPath)`, and (d) documents the
  cwd independence in the script docblock and `bin/README.md`. A verification
  test, `tests/Util/CheckTestSuitesScriptTest.php`, drives all three paths
  (`.dist` fallback, `.xml` precedence, config-dir-relative resolution) in temp
  trees. See `code-decision-2.md`.
- **Check that could catch it:** added — `tests/Util/CheckTestSuitesScriptTest.php`
  (3 tests, unit suite).

### R1-NIT — Assertion count in `findings-coder.md` is stale
- **Where:** `docs/proof_of_work/476-test-exclusion-guard/findings-coder.md:72`
- **What:** Records "8775 assertions"; the branch reports **8779** for
  `--testsuite unit`. Test count (1234) is correct.
- **Severity:** nit.
- **Origin:** new in this PR (record only).
- **Status:** **corrected in round 2** — `findings-coder.md` now records 8779
  (with an inline correction note). The round-1 verification test adds 3
  tests, so the final branch reports **1237 tests / 8782 assertions**.

## Round-1 summary

- `high`: none.
- `medium`: R1-1 (pre-existing, out-of-scope follow-up).
- `low`: R1-2, R1-3, R1-4.
- `nit`: R1-NIT.
- Gate behaviour, `phpunit.xml` additions, composer/CI wiring and the
  byte-identical `lint` context name were all independently verified; no
  correctness defect found in the new gate for this repository's configuration.

## Round 2 — coder response

- **R1-3 fixed:** `bin/check-test-suites.php:22` docblock → "`2` usage/config
  error".
- **R1-4 fixed:** config resolution now prefers `phpunit.xml`, falls back to
  `phpunit.xml.dist`, accepts a config file *or* directory, realpaths the
  config, and resolves relative entries plus `tests/` discovery against the
  config's directory; cwd independence documented. Verification test added:
  `tests/Util/CheckTestSuitesScriptTest.php` (3 tests / 7 assertions).
- **R1-1 answered, not fixed:** confirmed pre-existing follow-up candidate
  (`.github/workflows/ci.yml:85-95` vs `composer.json:34`, suggested fix =
  single `run: composer lint`). New gate step **is** in CI at `ci.yml:94-95`.
- **R1-2 answered, not fixed:** confirmed pre-existing (`phpcs.xml.dist:6-8`,
  `phpstan.neon` paths omit `bin/`).
- **R1-NIT fixed:** `findings-coder.md` 8775 → 8779, with note that the final
  branch is 1237 tests / 8782 assertions.

Round-2 gate: `bin/check-test-suites.php` exit `0` (149 files / 15 entries);
`composer lint` exit `0`; `./vendor/bin/phpunit --testsuite unit` OK
(1237 tests, 8782 assertions).

## Round 2 — reviewer verification (HEAD `04a1d6f`)

Full detail in `review-2.md`. Per-finding outcome:

- **R1-1 (medium, pre-existing):** re-confirmed and **answered as out of scope**
  — `ci.yml:85-95` still runs only `cs`/`rector`/`phpstan`/`test:suite-coverage`;
  `grep -rn 'kb-lint\|check-docs-links' .github/` → no match. The #476 gate
  **does** run in CI (`ci.yml:94-95`). Follow-up recorded.
- **R1-2 (low, pre-existing):** re-confirmed and **answered as out of scope** —
  `phpcs.xml.dist` files = `src`/`tests`/`examples`, `phpstan.neon` paths =
  `src`/`tests`; no `bin/`.
- **R1-3 (low, doc):** **fixed, verified** — `bin/check-test-suites.php:22` now
  "`2` usage/config error", consistent with `bin/README.md:86-87` and the
  exit-2 sources (`:60-65`, `:71-74`, `:105-108`, `:133-136`).
- **R1-4 (low, latent):** **fixed, verified** — `.xml` precedence reproduced
  (empty `.xml` beats valid `.dist` → exit 2); directory argument reproduced
  (`php … <dir>` with `.dist`-only tree → exit 0); config-relative resolution
  via `$configDir` at `:68/:132/:151/:164`; cwd independence reproduced from
  `/tmp`. New test `tests/Util/CheckTestSuitesScriptTest.php` genuinely
  discriminates and is itself inside `<directory>tests/Util</directory>`
  (`phpunit.xml:17`); `find tests -name '*Test.php'` = 149 = gate count, so no
  self-exemption. Standalone: `OK (3 tests, 7 assertions)`.
- **R1-NIT (nit):** **corrected, verified** — `findings-coder.md:72-79` records
  8779; base branch (`unit` minus new test) re-measured `1234 tests / 8779
  assertions`. Caveat: the assertion total is nondeterministic (four runs:
  8782/8786/8786/8782), isolated to the pre-existing, unrelated
  `ConsumerTest::testReadWaitsThroughResubscribeBackoffAfterLostSubscription`
  (143 vs 147 assertions). Test count 1237 is stable. Informational only.

New-issue sweep (`git diff main...HEAD`): the `./`-strip, boundary match, union
semantics, `<file>` exact match, reverse stale-check and `suffix`/`prefix`
absence are unchanged and correct. Only informational nits, none high/medium/low:

- **N2-1 (nit):** `<directory>/</directory>` rtrims to `''`, and `:125` then
  covers everything. Pathological config, not present.
- **N2-2 (nit):** absolute `<directory>`/`<file>` entries (legal PHPUnit) are
  treated as config-relative and would neither be reported stale nor cover
  anything. Not present; docblock could say "relative entries".
- **N2-3 (nit):** the pre-existing nondeterministic assertion count in NIT
  above.

Independent failure proof re-run: uncovered temp file → lists the file, `1 test
file(s) would never run in CI`, exit `1`; removal restores `149 … / 15 …`,
exit `0`; `git status --porcelain` clean. `composer lint` exit `0`;
`./vendor/bin/phpunit --testsuite unit` OK (1237 tests).

**Open findings:** no new high/medium/low introduced by this PR. The only open
items are the two accepted pre-existing out-of-scope follow-ups R1-1 (medium)
and R1-2 (low); everything else is resolved or informational.
