# Code Decision — Round 1 (issue #459)

## Context

`phpunit.xml` defines the `unit` testsuite with an explicit allow-list of
directories plus a single `<file>` entry. Six test files existed in the repo
but were never part of the allow-list, so `--testsuite unit` (the CI gate per
`.github/workflows/ci.yml`) never executed them:

- `tests/ResponseBuilderTest.php` (7 tests)
- `tests/Enum/KeyEnumTest.php` (9)
- `tests/Enum/ResponseCodeEnumTest.php` (6)
- `tests/Serializer/PhpBinarySerializerTest.php` (29)
- `tests/Trait/CommandTraitTest.php` (12)
- `tests/Contract/InterfaceImplementationTest.php` (3)

Total: 182 tests / 333 assertions, all passing when run directly via `--filter`.

## Approach chosen

Smallest correct change: extend the `unit` testsuite allow-list in
`phpunit.xml` with the four missing directories and the one missing file:

```xml
<directory>tests/Contract</directory>
<directory>tests/Enum</directory>
<directory>tests/Serializer</directory>
<directory>tests/Trait</directory>
<file>tests/ResponseBuilderTest.php</file>
```

No test code changes, no changes to the `e2e` testsuite, no changes to the
`<source>` block.

## Approaches rejected

1. **Catch-all `<directory>tests</directory>` with `<exclude>` for `tests/E2E`**
   — rejected. It would silently pick up any future stray test files (e.g.
   new top-level directories under `tests/`), and an explicit allow-list is
   what the project already uses; a catch-all is a bigger behavioural change
   than the issue asks for. It also risks breaking once `tests/E2E` gains
   subdirectories that the `<exclude>` pattern does not cover.
2. **Dropping the `<file>` entries in favour of directories** (moving
   `tests/StreamConnectionTest.php` / `tests/ResponseBuilderTest.php` into a
   directory) — rejected: physical file moves are unnecessary churn; PHPUnit
   supports `<file>` alongside `<directory>`, and the existing layout already
   uses it for the two top-level test files.

## Uncertainties

- None material. The order of `<directory>` entries is alphabetical by
  directory name, which matches the pre-existing convention (`Buffer`,
  `Client`, `Request`, `Response`, `Util`, `VO`).
- `tests/Trait/Fixtures/` lives under `tests/Trait` but contains no `*Test.php`
  files, and PHPUnit's default suffix is `Test.php`, so it is not picked up as
  tests. Confirmed by the run output (no fixture files executed).
