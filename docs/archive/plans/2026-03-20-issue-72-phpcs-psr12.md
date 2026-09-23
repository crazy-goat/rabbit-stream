# Issue #72: Add PHP_CodeSniffer with PSR-12 Standard

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add PHP_CodeSniffer (PHPCS) with PSR-12 coding standard to enforce code style consistency across the codebase.

**Architecture:** Install `phpcsstandards/php_codesniffer` (new official package) as dev dependency, configure it with PSR-12 standard via XML config, add composer scripts for easy linting, and fix all existing style violations.

**Tech Stack:** PHP 8.1+, Composer, phpcsstandards/php_codesniffer, PSR-12 standard

---

## Task 1: Add PHPCS to composer.json

**Files:**
- Modify: `composer.json:24-26`

**Step 1: Add PHPCS to require-dev**

```json
"require-dev": {
    "phpunit/phpunit": "^10.5",
    "phpcsstandards/php_codesniffer": "^3.9"
}
```

**Step 2: Install dependencies**

Run: `composer install`
Expected: PHPCS installed in vendor/bin/phpcs

**Step 3: Verify installation**

Run: `vendor/bin/phpcs --version`
Expected: `PHP_CodeSniffer version 3.9.x` (PHPCSStandards edition)

**Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add PHP_CodeSniffer ^3.9 to dev dependencies (issue #72)"
```

---

## Task 2: Create PHPCS Configuration

**Files:**
- Create: `phpcs.xml.dist`

**Step 1: Create PSR-12 configuration file**

```xml
<?xml version="1.0"?>
<ruleset name="RabbitStream">
    <description>RabbitStream coding standard based on PSR-12</description>
    
    <!-- Scan src and tests directories -->
    <file>src</file>
    <file>tests</file>
    <file>examples</file>
    
    <!-- Exclude vendor and cache -->
    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>*.cache</exclude-pattern>
    
    <!-- Use PSR-12 standard -->
    <rule ref="PSR12"/>
    
    <!-- Show progress -->
    <arg value="p"/>
    
    <!-- Show sniff codes in report -->
    <arg value="s"/>
    
    <!-- Use colors in output -->
    <arg name="colors"/>
</ruleset>
```

**Step 2: Test configuration**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary`
Expected: List of files with violations (we'll fix them next)

**Step 3: Commit**

```bash
git add phpcs.xml.dist
git commit -m "chore: add PHPCS configuration with PSR-12 standard (issue #72)"
```

---

## Task 3: Run Initial PHPCS Scan

**Files:**
- None (read-only scan)

**Step 1: Run full scan to see all violations**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist`
Expected: Output showing all PSR-12 violations across src/, tests/, examples/

**Step 2: Save report for reference**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist --report=json > phpcs-report.json`

**Step 3: Note the violations count**

Expected: Report showing violations like:
- Missing namespace declarations
- Incorrect brace placement
- Wrong indentation
- Missing docblocks
- etc.

---

## Task 4: Auto-fix Violations with PHPCBF

**Files:**
- All PHP files in src/, tests/, examples/

**Step 1: Run PHPCBF to auto-fix what it can**

Run: `vendor/bin/phpcbf --standard=phpcs.xml.dist`
Expected: Output showing "X fixes applied"

**Step 2: Check remaining violations**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary`
Expected: Fewer violations than before (only non-auto-fixable ones remain)

**Step 3: Commit auto-fixes**

```bash
git add -A
git commit -m "style: auto-fix PSR-12 violations with phpcbf (issue #72)"
```

---

## Task 5: Manually Fix Remaining Violations

**Files:**
- Various files in src/, tests/, examples/ (to be determined by scan)

**Step 1: Run detailed scan to see remaining issues**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist`
Expected: Detailed list of remaining violations with file paths and line numbers

**Step 2: Fix violations one by one**

For each violation reported:
- Open the file
- Fix the specific issue (missing namespace, wrong brace style, etc.)
- Save

Common fixes needed:
- Add proper namespace declarations
- Fix brace placement (PSR-12: opening brace on same line for classes/methods)
- Fix indentation (4 spaces)
- Add file-level docblocks if missing
- Fix line length issues (max 120 chars)

**Step 3: Verify all violations fixed**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist`
Expected: `No errors detected`

**Step 4: Commit manual fixes**

```bash
git add -A
git commit -m "style: manually fix remaining PSR-12 violations (issue #72)"
```

---

## Task 6: Add Composer Scripts

**Files:**
- Modify: `composer.json` (add scripts section)

**Step 1: Add scripts to composer.json**

Add after `require-dev` section:

```json
"scripts": {
    "lint": "phpcs --standard=phpcs.xml.dist",
    "lint:fix": "phpcbf --standard=phpcs.xml.dist",
    "test": "phpunit",
    "test:unit": "phpunit --testsuite unit",
    "test:e2e": "phpunit --testsuite e2e"
}
```

**Step 2: Test composer scripts**

Run: `composer lint`
Expected: `No errors detected`

Run: `composer lint:fix`
Expected: No output or "No fixable errors were found"

**Step 3: Commit**

```bash
git add composer.json
git commit -m "chore: add composer scripts for linting and testing (issue #72)"
```

---

## Task 7: Update AGENTS.md Documentation

**Files:**
- Modify: `AGENTS.md` (add PHPCS commands to Build/Lint/Test Commands section)

**Step 1: Update the commands section**

Replace the existing commands section with:

```markdown
## Build / Lint / Test Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit
# OR
composer test

# Run only unit tests
./vendor/bin/phpunit --testsuite unit
# OR
composer test:unit

# Run E2E tests (requires RabbitMQ — use the script below)
./run-e2e.sh

# Run PHP_CodeSniffer (lint)
./vendor/bin/phpcs --standard=phpcs.xml.dist
# OR
composer lint

# Auto-fix code style violations
./vendor/bin/phpcbf --standard=phpcs.xml.dist
# OR
composer lint:fix

# Run a single test file
./vendor/bin/phpunit tests/Request/SaslHandshakeRequestV1Test.php

# Run a single test method
./vendor/bin/phpunit --filter testSerializesCorrectly

# Run with verbose output
./vendor/bin/phpunit --testdox
```
```

**Step 2: Verify documentation**

Run: `composer lint`
Expected: `No errors detected`

**Step 3: Commit**

```bash
git add AGENTS.md
git commit -m "docs: add PHPCS commands to AGENTS.md (issue #72)"
```

---

## Task 8: Final Verification

**Files:**
- None (verification only)

**Step 1: Run full test suite**

Run: `composer test:unit`
Expected: All tests pass

**Step 2: Run linter one more time**

Run: `composer lint`
Expected: `No errors detected`

**Step 3: Check git status**

Run: `git status`
Expected: Working tree clean

**Step 4: Show summary**

Run: `git log --oneline -8`
Expected: See all 8 commits from this plan

---

## Summary

After completing this plan:
- ✅ PHP_CodeSniffer ^3.9 (PHPCSStandards edition) installed
- ✅ PSR-12 standard configured via phpcs.xml.dist
- ✅ All existing code style violations fixed
- ✅ Composer scripts added (`composer lint`, `composer lint:fix`)
- ✅ AGENTS.md updated with new commands
- ✅ All tests still pass
- ✅ Clean git history with 8 commits

**Package used:** `phpcsstandards/php_codesniffer` (official successor to squizlabs/php_codesniffer)

**Next Steps:**
- Close issue #72 via GitHub CLI: `gh issue close 72`
- Update README.md to mark PHPCS as implemented (if there's a checklist)
- Update CHANGELOG.md under [Unreleased] section
