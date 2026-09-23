# Findings — issue #563

## Scope

Add `bin/` to PHPCS and PHPStan coverage and fix the findings exposed in the repository's CLI/gate scripts.

## Findings

- `bin/check-docs-links.php`: PHPStan could not narrow `RecursiveIteratorIterator` values to `SplFileInfo`; an explicit guard is required before file methods are called.
- `bin/check-test-suites.php`: `DOMXPath::query()` can return `false`; both query sites now fail explicitly with exit code 2 instead of being iterated blindly.
- `bin/kb-lint.php`: the local `Entry` PHPStan alias was not visible as a global type when the script entered analysis; the alias is now configured centrally, `validateEntry()` declares `list<string>`, and CLI argv is narrowed before `parseArgs()`.
- `bin/kb-lint.php` intentionally both declares helper symbols and executes the CLI entrypoint. Only `PSR1.Files.SideEffects` is excluded for that file; the rest of PHPCS still covers it.

## Verification

The branch was exercised through the repository's CI workflow after the changes. PHPCS, Rector, PHPStan, test-suite coverage, and the PHP 8.1–8.4 unit-test matrix passed before the upstream PR was prepared. E2E is also allowed to complete before submission.
