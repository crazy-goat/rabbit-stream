# Findings — Review (issue #459)

Round 1 (first round — no prior entries). One entry per finding; coder
findings ruled on explicitly, then review's own findings.

## Rulings on coder findings (findings-coder.md)

### coder-1 — Risky test `testDispatchMetadataUpdateWithoutCallbackDoesNotCrash` (`tests/StreamConnectionTest.php:567`)
- **Verdict:** real, pre-existing, **not a defect of this PR**, already tracked as **#454**. The file was in the unit allow-list before this change; the risky flag is unchanged by the diff. Severity: none (for this PR). No action.
- Automated check that would catch it: PHPUnit's own risky-test detection (already active — it reports "This test did not perform any assertions").

### coder-2 — Uncommitted modification of `docs/workflow.md` in the working tree
- **Verdict:** verified — the modification exists in the working tree and is **not** part of commit `6259a30` (commit stat: `phpunit.xml` + 2 PoW files only). Not a code finding; orchestration note: the main session must ensure `git add -A` in later steps does not sweep `docs/workflow.md` into the PR — stash or discard it before merging.
- Severity: none (note only).

### coder-3 — Fragile allow-list: future `tests/**/*Test.php` outside the allow-list silently stays out of CI
- **Verdict:** real place-to-improve, **low** severity. This PR fixes the symptom (the 6 files now run); it does not prevent recurrence. Deliberately **not fixed in this round**: adding a guard-rail (e.g. a composer script or CI step that fails when a discovered `*Test.php` exists outside the allow-list) is a new CI mechanism beyond the minimal config-only scope of #459.
- **Recommendation:** file a follow-up issue — "Prevent silent test exclusions: CI check that validates phpunit.xml coverage of `tests/**/*Test.php`". This is the first occurrence of this defect class, so no prior issue exists; a check is preferred over a knowledge-base entry (workflow.md: "Prefer a gate over an entry").
- Automated check that would catch it: a small script (`composer test:suite-coverage` or a CI step) comparing `find tests -name '*Test.php' -not -path 'tests/E2E/*'` against the configured allow-list.

### coder-4 — `phpunit.xml` has no `<coverage>` configuration
- **Verdict:** by-design and documented — AGENTS.md and `docs/workflow.md` explicitly state "no coverage gate; do not invent one". **Not a real finding**; skip.

## Review's own findings (round 1)

### review-1 — Fixture risk: `tests/Trait/Fixtures/TestCommand.php` under a now-included directory
- `phpunit.xml:11` (`<directory>tests/Trait</directory>`)
- **Verdict:** not a finding — verified. PHPUnit 10.5's default suffix is `Test.php`; `TestCommand.php` does not match. Empirical proof: full unit run reports exactly 844 tests (662 pre-change + 182 added) and `TestCommand` appears 0 times in output. No action.

### review-2 — Commit-message scope `phpunit` not among the documented scope examples
- **Verdict:** nit, acceptable — AGENTS.md's scope list ends with "etc." and the type `test` + `(closes #459)` follow the convention. No action.

## Status summary

- Round 1: **clean**. No findings requiring fixes in this PR.
- Open recommendations carried over (not blockers): follow-up issue for the guard-rail check (coder-3); main session must keep `docs/workflow.md` out of the PR (coder-2).
