#!/usr/bin/env bash
# Run all static analysis, linters and formatter checks. --fix applies fixes first.
# Contract: https://github.com/crazy-goat/.github/blob/main/standard/lint.md
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1
failed=()

step() {
    local name="$1"
    shift
    echo "==> $name"
    "$@" || failed+=("$name")
}

if [ "$FIX" = 1 ]; then
    echo "==> fix"
    vendor/bin/rector process || true
    vendor/bin/phpcbf --standard=phpcs.xml.dist || true
    php bin/kb-lint.php --fix || true
fi

step "phpcs" vendor/bin/phpcs --standard=phpcs.xml.dist
step "rector" vendor/bin/rector process --dry-run
step "phpstan" vendor/bin/phpstan analyse
step "kb-lint" php bin/kb-lint.php
step "docs-links" php bin/check-docs-links.php
step "stale-sockets" php bin/check-stale-sockets.php
step "test-suite-coverage" php bin/check-test-suites.php
step "shellcheck" bash -c '{ git ls-files -z "*.sh"; git ls-files -z "bin/hooks/*"; } | xargs -0 -r shellcheck'

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}" >&2
    exit 1
fi
echo "All checks passed."
