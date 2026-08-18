# Review — Round 1 (issue #459)

Branch: `feature/issue-459-unit-testsuite-excluded-files`
Diff reviewed: `git diff origin/main -- phpunit.xml` (5 insertions, config-only)
Reviewer role: read-only subagent (no commit; main session commits this file)

## Prior findings (findings-review.md)

This is round 1 — `findings-review.md` did not exist before this review, so
there are no earlier-round findings to revisit.

## The diff

```xml
<testsuite name="unit">
    <directory>tests/Buffer</directory>
    <directory>tests/Client</directory>
+   <directory>tests/Contract</directory>
+   <directory>tests/Enum</directory>
    <directory>tests/Request</directory>
    <directory>tests/Response</directory>
+   <directory>tests/Serializer</directory>
+   <directory>tests/Trait</directory>
    <directory>tests/Util</directory>
    <directory>tests/VO</directory>
+   <file>tests/ResponseBuilderTest.php</file>
    <file>tests/StreamConnectionTest.php</file>
</testsuite>
```

## Check 1 — Correctness: are all 6 previously-excluded files now covered?

| File | Coverage via | Verified |
| --- | --- | --- |
| `tests/ResponseBuilderTest.php` | `<file>` | ✅ runs (filtered) |
| `tests/Enum/KeyEnumTest.php` | `tests/Enum` dir | ✅ runs (filtered) |
| `tests/Enum/ResponseCodeEnumTest.php` | `tests/Enum` dir | ✅ runs (filtered) |
| `tests/Serializer/PhpBinarySerializerTest.php` | `tests/Serializer` dir | ✅ runs (filtered) |
| `tests/Trait/CommandTraitTest.php` | `tests/Trait` dir | ✅ runs (filtered) |
| `tests/Contract/InterfaceImplementationTest.php` | `tests/Contract` dir | ✅ runs (filtered) |

Filtered gate: `OK (182 tests, 333 assertions)` — exactly the counts claimed in
issue #459. No other non-E2E `*Test.php` file exists outside the allow-list:
a script walk of `tests/` found **87 non-E2E `*Test.php` files, 87 covered,
0 uncovered** after this diff (before the diff, 6 were uncovered — verified
manually against the old allow-list).

## Check 2 — No collateral

- `e2e` testsuite unchanged: still only `<directory>tests/E2E</directory>`.
- No test file was moved.
- `<source><include><directory>src</directory></include></source>` untouched.

## Check 3 — Ordering / format

Directory entries remain alphabetical (`Buffer, Client, Contract, Enum,
Request, Response, Serializer, Trait, Util, VO`); `<file>` entries alphabetical
(`ResponseBuilderTest.php` before `StreamConnectionTest.php`); 12-space
indentation matches existing entries. Consistent with the pre-existing
convention.

## Check 4 — Side effects

- `tests/Trait/Fixtures/TestCommand.php` does **not** end with the default
  PHPUnit suffix `Test.php`, so it is not discovered. Empirically confirmed:
  the full unit run reports exactly 844 tests (662 before + 182 added) and the
  fixture class name appears 0 times in the run output.
- `tests/Contract/InterfaceImplementationTest.php` has no hidden dependencies:
  it only uses autoloaded, already-shipped classes (`Connection`, `Producer`,
  `Consumer` + the `Contract` interfaces) and `is_subclass_of`. No extension
  required.

## Check 5 — Gates (all run locally on this branch)

| Gate | Result |
| --- | --- |
| `composer cs` (PHPCS PSR-12) | ✅ exit 0 |
| `composer phpstan` (level 9) | ✅ exit 0, "No errors" |
| `./vendor/bin/phpunit --testsuite unit` | ✅ exit 0 — Tests: 844, Assertions: 2786, Risky: 1 (pre-existing, #454) |
| Filtered (the 6 files) | ✅ exit 0 — 182 tests, 333 assertions |

E2E not run: config-only change, no wire-level impact (matches workflow step 3:
"E2E only for protocol-level changes").

## Check 6 — Ruling on the coder's findings

See `findings-review.md` for the entries. Summary:

1. Risky test #454 — **real, pre-existing, already tracked** (#454). Not a
   defect of this PR.
2. Uncommitted `docs/workflow.md` — **not a code finding**; verified it is not
   in the commit; orchestration note for the main session.
3. Fragile allow-list pattern — **real place-to-improve, low severity**;
   deliberately not fixed here (scope), recommended as a follow-up issue with
   an automatable guard-rail check.
4. No `<coverage>` config — **by-design**, documented in AGENTS.md/workflow.md
   ("no coverage gate"); skip.

## Check 7 — AGENTS.md conventions

N/A for PHP code (no PHP changed). Branch name follows
`feature/issue-NNN-<slug>`; commit type `test` and `(closes #459)` reference
follow the documented convention. Scope `phpunit` is not among the listed
examples but the list ends with "etc." and the scope is descriptive — accepted.

## Verdict

**Clean — no open findings.** The change is exactly the minimal correct fix,
verified by execution. Round 1 has no findings requiring fixes.

## New findings

None.
