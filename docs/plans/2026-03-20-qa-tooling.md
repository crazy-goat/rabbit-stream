# QA Tooling Implementation Plan (PHPStan + PHPCS + Rector)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add PHPStan (all levels, one task per level), PHP_CodeSniffer (PSR-12), and Rector (dead code + code quality + PHP 8.1 upgrade) as dev dependencies with CI integration.

**Architecture:** Each tool is installed via Composer, configured with a dedicated config file, wired into `composer.json` scripts, and added as a separate CI job in `.github/workflows/ci.yml`. PHPStan is introduced level-by-level — each level is a separate task that installs, fixes violations, and commits before moving to the next.

**Tech Stack:** PHP 8.1+, PHPStan, squizlabs/php_codesniffer, rector/rector, GitHub Actions (`shivammathur/setup-php@v2`)

---

## Task 1: Install PHP_CodeSniffer and configure PSR-12

**Files:**
- Modify: `composer.json`
- Create: `phpcs.xml`
- Modify: `.github/workflows/ci.yml`

**Step 1: Install PHPCS via Composer**

```bash
composer require --dev squizlabs/php_codesniffer
```

Expected: `squizlabs/php_codesniffer` appears in `composer.json` `require-dev`.

**Step 2: Create `phpcs.xml`**

```xml
<?xml version="1.0"?>
<ruleset name="RabbitStream">
    <description>PSR-12 coding standard</description>

    <file>src</file>
    <file>tests</file>

    <arg name="basepath" value="."/>
    <arg name="colors"/>
    <arg value="p"/>

    <rule ref="PSR12"/>
</ruleset>
```

**Step 3: Run PHPCS to see current violations**

```bash
./vendor/bin/phpcs
```

Fix all reported violations manually or with:

```bash
./vendor/bin/phpcbf
```

Re-run until exit code is 0:

```bash
./vendor/bin/phpcs
echo $?
```

Expected: exit code `0`, no violations.

**Step 4: Add Composer scripts**

In `composer.json`, add:

```json
"scripts": {
    "cs": "phpcs",
    "cs-fix": "phpcbf"
}
```

**Step 5: Add CI job to `.github/workflows/ci.yml`**

Add after the `unit-tests` job:

```yaml
  code-style:
    name: Code Style (PHPCS)
    needs: check-actor
    if: needs.check-actor.outputs.allowed == 'true'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run PHPCS
        run: ./vendor/bin/phpcs
```

**Step 6: Commit**

```bash
git add composer.json composer.lock phpcs.xml .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: add PHP_CodeSniffer with PSR-12 standard"
```

---

## Task 2: Install Rector and configure dead code + code quality + PHP 8.1

**Files:**
- Modify: `composer.json`
- Create: `rector.php`
- Modify: `.github/workflows/ci.yml`

**Step 1: Install Rector via Composer**

```bash
composer require --dev rector/rector
```

Expected: `rector/rector` appears in `composer.json` `require-dev`.

**Step 2: Create `rector.php`**

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_81,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
    ]);
```

**Step 3: Run Rector in dry-run to see what it would change**

```bash
./vendor/bin/rector process --dry-run
```

Review the proposed changes.

**Step 4: Apply Rector changes**

```bash
./vendor/bin/rector process
```

Run tests to verify nothing broke:

```bash
./vendor/bin/phpunit --testsuite unit
```

Expected: all tests pass.

**Step 5: Re-run PHPCS after Rector changes and fix any new violations**

```bash
./vendor/bin/phpcbf
./vendor/bin/phpcs
```

Expected: exit code `0`.

**Step 6: Add Composer scripts**

In `composer.json` scripts section, add:

```json
"rector": "rector process --dry-run",
"rector-fix": "rector process"
```

**Step 7: Add CI job to `.github/workflows/ci.yml`**

```yaml
  rector-check:
    name: Rector (dry-run)
    needs: check-actor
    if: needs.check-actor.outputs.allowed == 'true'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run Rector dry-run
        run: ./vendor/bin/rector process --dry-run
```

**Step 8: Commit**

```bash
git add composer.json composer.lock rector.php .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: add Rector with dead code, code quality, and PHP 8.1 rule sets"
```

---

## Task 3: Install PHPStan and fix level 0

**Files:**
- Modify: `composer.json`
- Create: `phpstan.neon`
- Modify: `.github/workflows/ci.yml`

**Step 1: Install PHPStan via Composer**

```bash
composer require --dev phpstan/phpstan
```

**Step 2: Create `phpstan.neon` at level 0**

```neon
parameters:
    level: 0
    paths:
        - src
        - tests
```

**Step 3: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error in `src/` and `tests/`. Re-run until exit code is 0:

```bash
./vendor/bin/phpstan analyse
echo $?
```

Expected: exit code `0`, `[OK] No errors`.

**Step 4: Add Composer script**

```json
"phpstan": "phpstan analyse"
```

**Step 5: Add CI job to `.github/workflows/ci.yml`**

```yaml
  static-analysis:
    name: PHPStan (level 0)
    needs: check-actor
    if: needs.check-actor.outputs.allowed == 'true'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run PHPStan
        run: ./vendor/bin/phpstan analyse
```

**Step 6: Run unit tests to verify nothing broke**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 7: Commit**

```bash
git add composer.json composer.lock phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: add PHPStan at level 0, all violations fixed"
```

---

## Task 4: PHPStan level 1

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level in `phpstan.neon`**

Change:
```neon
    level: 0
```
To:
```neon
    level: 1
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean:

```bash
./vendor/bin/phpstan analyse && echo "CLEAN"
```

**Step 3: Update CI job name**

In `.github/workflows/ci.yml`, change:
```yaml
    name: PHPStan (level 0)
```
To:
```yaml
    name: PHPStan (level 1)
```

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 1, all violations fixed"
```

---

## Task 5: PHPStan level 2

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 2
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 2)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 2, all violations fixed"
```

---

## Task 6: PHPStan level 3

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 3
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 3)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 3, all violations fixed"
```

---

## Task 7: PHPStan level 4

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 4
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 4)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 4, all violations fixed"
```

---

## Task 8: PHPStan level 5

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 5
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 5)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 5, all violations fixed"
```

---

## Task 9: PHPStan level 6

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 6
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 6)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 6, all violations fixed"
```

---

## Task 10: PHPStan level 7

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 7
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 7)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 7, all violations fixed"
```

---

## Task 11: PHPStan level 8

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 8
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 8)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 8, all violations fixed"
```

---

## Task 12: PHPStan level 9 (max)

**Files:**
- Modify: `phpstan.neon`
- Modify: `src/**` and/or `tests/**` as needed

**Step 1: Bump level**

```neon
    level: 9
```

**Step 2: Run PHPStan and fix all reported errors**

```bash
./vendor/bin/phpstan analyse
```

Fix every reported error. Re-run until clean.

**Step 3: Update CI job name to `PHPStan (level 9)`**

**Step 4: Run unit tests**

```bash
./vendor/bin/phpunit --testsuite unit
```

**Step 5: Commit**

```bash
git add phpstan.neon .github/workflows/ci.yml
git add -u src/ tests/
git commit -m "feat: bump PHPStan to level 9 (max), all violations fixed"
```

---

## Task 13: Add `php` version constraint to `composer.json`

**Files:**
- Modify: `composer.json`

**Step 1: Add PHP constraint**

In `composer.json`, in the `require` section, add:

```json
"php": ">=8.1"
```

**Step 2: Verify**

```bash
composer validate
```

Expected: `./composer.json is valid`.

**Step 3: Update AGENTS.md with QA commands**

In `AGENTS.md`, add a new section `## QA Commands`:

```markdown
## QA Commands

\`\`\`bash
composer phpstan     # static analysis (PHPStan)
composer cs          # check code style (PHPCS PSR-12)
composer cs-fix      # auto-fix code style (phpcbf)
composer rector      # preview refactoring suggestions (dry-run)
composer rector-fix  # apply Rector refactoring
\`\`\`
```

**Step 4: Commit**

```bash
git add composer.json AGENTS.md
git commit -m "chore: add php>=8.1 constraint and document QA commands in AGENTS.md"
```
