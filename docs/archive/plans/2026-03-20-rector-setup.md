# Rector Setup Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Install Rector, configure it with DEAD_CODE, CODE_QUALITY, and UP_TO_PHP_81 rule sets, apply all suggested changes, and add a CI dry-run job.

**Architecture:** Rector is a dev-only tool. It runs on `src/` and `tests/`, proposes refactors, and we apply them. After applying, unit tests must still pass and phpcs must report no violations. CI gets a new `rector-check` job that runs `--dry-run` (exits 0 = no changes left).

**Tech Stack:** PHP 8.1+, Rector, PHPUnit, PHP_CodeSniffer

---

### Task 1: Install Rector

**Files:**
- Modify: `composer.json` (via composer command)

**Step 1: Install rector as dev dependency**

```bash
composer require --dev rector/rector
```

Expected: `rector/rector` appears in `require-dev` in `composer.json`, binary at `./vendor/bin/rector`.

**Step 2: Verify binary works**

```bash
./vendor/bin/rector --version
```

Expected: prints Rector version string.

**Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: install rector/rector as dev dependency"
```

---

### Task 2: Create rector.php configuration

**Files:**
- Create: `rector.php`

**Step 1: Create the config file**

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

**Step 2: Verify config is valid (dry-run preview)**

```bash
./vendor/bin/rector process --dry-run
```

Expected: exits with code 0 or lists proposed changes (non-zero exit = changes found). Note what changes are proposed — they will be applied in Task 3.

**Step 3: Commit config**

```bash
git add rector.php
git commit -m "chore: add rector.php with DEAD_CODE, CODE_QUALITY, UP_TO_PHP_81 sets"
```

---

### Task 3: Apply Rector changes

**Files:**
- Modify: various files in `src/` and `tests/` (Rector decides)

**Step 1: Apply all Rector suggestions**

```bash
./vendor/bin/rector process
```

Expected: Rector modifies files and exits 0.

**Step 2: Run unit tests — must pass**

```bash
./vendor/bin/phpunit --testsuite unit
```

Expected: all tests pass (green). If any fail, investigate and fix manually.

**Step 3: Fix any new code style violations**

```bash
./vendor/bin/phpcbf --standard=phpcs.xml.dist
./vendor/bin/phpcs --standard=phpcs.xml.dist
```

Expected: phpcs exits 0 (no violations).

**Step 4: Confirm dry-run is clean**

```bash
./vendor/bin/rector process --dry-run
```

Expected: exits 0, no changes proposed.

**Step 5: Commit all Rector-applied changes**

```bash
git add -A
git commit -m "refactor: apply Rector (dead code, code quality, PHP 8.1 upgrades)"
```

---

### Task 4: Add Composer scripts

**Files:**
- Modify: `composer.json`

**Step 1: Add rector scripts to composer.json**

In the `"scripts"` section, add:

```json
"rector": "rector process --dry-run",
"rector:fix": "rector process"
```

Final scripts section should look like:

```json
"scripts": {
    "lint": "phpcs --standard=phpcs.xml.dist",
    "lint:fix": "phpcbf --standard=phpcs.xml.dist",
    "test": "phpunit",
    "test:unit": "phpunit --testsuite unit",
    "test:e2e": "phpunit --testsuite e2e",
    "rector": "rector process --dry-run",
    "rector:fix": "rector process"
}
```

**Step 2: Verify scripts work**

```bash
composer rector
```

Expected: exits 0 (no changes proposed).

**Step 3: Commit**

```bash
git add composer.json
git commit -m "chore: add rector and rector:fix composer scripts"
```

---

### Task 5: Add rector-check CI job

**Files:**
- Modify: `.github/workflows/ci.yml`

**Step 1: Add rector-check job**

Add the following job after the `lint` job (it should `needs: [check-actor, lint]`):

```yaml
  rector-check:
    name: Rector (dry-run)
    needs: [check-actor, lint]
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

**Step 2: Verify YAML is valid**

```bash
cat .github/workflows/ci.yml
```

Check indentation is consistent (2 spaces throughout).

**Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add rector-check job (dry-run on PHP 8.4)"
```

---

### Task 6: Final verification

**Step 1: Run full local check**

```bash
./vendor/bin/rector process --dry-run
./vendor/bin/phpunit --testsuite unit
./vendor/bin/phpcs --standard=phpcs.xml.dist
```

All three must exit 0.

**Step 2: Push branch and open PR**

```bash
git push -u origin feature/issue-73-rector
gh pr create --title "feat: add Rector with dead code, code quality, and PHP 8.1 rule sets" --body "Closes #73"
```
