# Review round 1 — issue #563

## Scope reviewed

- `phpcs.xml.dist`
- `phpstan.neon`
- `bin/check-docs-links.php`
- `bin/check-test-suites.php`
- `bin/kb-lint.php`

## Review

The change keeps the existing lint entrypoints intact and expands their coverage to `bin/`. The PHPCS exception is limited to `PSR1.Files.SideEffects` for the executable `kb-lint.php` script; all other coding-standard rules still apply. PHPStan remains at level 9 and receives only the shared `Entry` shape needed by the existing annotations.

The source fixes do not change the intended successful behavior of the CLI tools. New error branches for failed XPath queries use the script's existing configuration/usage exit code (`2`). Iterator narrowing is defensive and does not alter valid `SplFileInfo` processing.

## Verification

The implementation was exercised in GitHub Actions with PHPCS, Rector dry-run, PHPStan, test-suite coverage, and the PHP 8.1–8.4 unit matrix. The repository E2E job is also part of the final branch CI before upstream submission.

## Disposition

No open review findings.
