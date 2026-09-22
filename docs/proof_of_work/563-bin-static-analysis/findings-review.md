# Review findings — issue #563

## Round 1

No unresolved correctness findings remain in the final patch.

During verification, enabling PHPCS on `bin/` exposed file-header violations in the gate scripts and the intentional PSR-1 side-effect warning in `kb-lint.php`. Enabling PHPStan exposed unchecked XPath query results, iterator type ambiguity, the `Entry` alias visibility problem, a missing iterable return value type, and un-narrowed CLI arguments. Each was either fixed in source or, for the intentional CLI side-effect warning only, excluded at the narrowest applicable PHPCS rule/file scope.

Automated gates covering these findings are now the existing `composer cs` and `composer phpstan` commands themselves because `bin/` is part of both configurations.
