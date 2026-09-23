# Code decision — round 1

## Decision

Extend the existing PHPCS and PHPStan configurations to cover `bin/` rather than creating a separate lint command. This keeps `composer cs`, `composer phpstan`, and therefore `composer lint`, as the single source of truth.

## Resulting fixes

The newly-covered scripts exposed real pre-existing static-analysis findings. The patch fixes those at the source where practical: iterator narrowing in the docs-link checker, explicit `DOMXPath::query()` failure handling in the test-suite checker, and stronger PHPStan typing around the knowledge-base linter.

For `bin/kb-lint.php`, the PSR-1 side-effect warning is intentionally scoped out because this file is both a collection of CLI helper functions and the executable entrypoint. Excluding that one sniff for that one file is narrower than excluding the script or the whole `bin/` directory from PHPCS.

## Rejected alternatives

- Excluding `bin/kb-lint.php` entirely from PHPCS: would defeat issue #563 by leaving a gate script partially unchecked.
- Adding broad PHPStan ignores for `bin/`: would hide future type regressions.
- Splitting the CLI entrypoint into a separate file solely to satisfy the side-effect sniff: larger structural change than this CI-coverage issue requires.
