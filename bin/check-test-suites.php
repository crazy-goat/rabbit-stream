<?php

/**
 * Test-suite coverage gate (#476).
 *
 * `phpunit.xml` lists every test file a suite will run. A `tests/**\/*Test.php`
 * file that is not under any suite's `<directory>`/`<file>` allow-list is
 * silently never discovered by `phpunit --testsuite …` — green CI, zero
 * coverage, exactly the #459 failure mode (six files, 182 tests unrun).
 *
 * This script diffs the test files present on disk against the union of every
 * suite's allow-list in `phpunit.xml` and fails if any file is in neither.
 * Parsing the config (rather than hard-coding paths) keeps the gate in sync
 * automatically: adding a suite entry is all that is needed to cover a path.
 *
 * It also fails the reverse direction — an allow-list `<directory>`/`<file>`
 * that no longer exists on disk — so a rename cannot silently leave the suite
 * pointing at nothing.
 *
 * Usage: php bin/check-test-suites.php [phpunit.xml | directory]
 * Exit codes: 0 clean, 1 uncovered/stale paths, 2 usage/config error.
 *
 * Config resolution matches PHPUnit's own precedence: `phpunit.xml` wins over
 * `phpunit.xml.dist`. An explicit argument is used as-is when it names a file;
 * when it names a directory, the `phpunit.xml` / `phpunit.xml.dist` pair is
 * looked up inside it. With no argument the same lookup runs against the
 * repository root (the script's parent directory), so the script is
 * independent of the current working directory. Relative `<directory>`/`<file>`
 * entries and the `tests/` discovery root are resolved against the config's own
 * directory, not the repository root.
 *
 * Assumes the repository's conventions: tests live under `tests/`, are named
 * `*Test.php`, and the allow-lists are plain `<directory>`/`<file>` entries
 * (no `suffix`/`prefix` attributes). The `tests/` prefix is not hard-coded
 * beyond discovery; a suite entry covers whatever path it names.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * Prefer `phpunit.xml`, fall back to `phpunit.xml.dist` (PHPUnit precedence).
 * Returns null when neither exists directly under $base.
 */
$resolveConfig = static function (string $base): ?string {
    $base = rtrim($base, '/');
    foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
        if (is_file($base . '/' . $name)) {
            return $base . '/' . $name;
        }
    }
    return null;
};

$configArg = $argv[1] ?? null;
$configPath = $configArg === null
    ? $resolveConfig($root)
    : (is_dir($configArg) ? $resolveConfig($configArg) : $configArg);

if ($configPath === null || !is_file($configPath)) {
    $requested = $configArg ?? $root;
    fwrite(STDERR, "check-test-suites: phpunit config not found for {$requested}\n");
    fwrite(STDERR, "Usage: php bin/check-test-suites.php [phpunit.xml | directory]\n");
    exit(2);
}

$configPath = realpath($configPath) ?: $configPath;
$configDir = dirname($configPath);

$dom = new DOMDocument();
if (!@$dom->load($configPath)) {
    fwrite(STDERR, "check-test-suites: could not parse {$configPath}\n");
    exit(2);
}

$xpath = new DOMXPath($dom);

/** @var array<int, array{type: string, path: string, suite: string, line: int}> $allowList */
$allowList = [];
$suites = $xpath->query('/phpunit/testsuites/testsuite');
if ($suites === false) {
    fwrite(STDERR, "check-test-suites: could not query testsuites in {$configPath}\n");
    exit(2);
}

foreach ($suites as $suite) {
    if (!$suite instanceof DOMElement) {
        continue;
    }
    $suiteName = $suite->getAttribute('name');
    $entries = $xpath->query('directory|file', $suite);
    if ($entries === false) {
        fwrite(STDERR, "check-test-suites: could not query allow-list entries in {$configPath}\n");
        exit(2);
    }
    foreach ($entries as $entry) {
        if (!$entry instanceof DOMElement) {
            continue;
        }
        $path = str_replace('\\', '/', trim($entry->textContent));
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '') {
            continue;
        }
        $allowList[] = [
            'type' => $entry->nodeName,
            'path' => $path,
            'suite' => $suiteName,
            'line' => $entry->getLineNo(),
        ];
    }
}

if ($allowList === []) {
    fwrite(STDERR, "check-test-suites: {$configPath} defines no <directory>/<file> allow-list entries\n");
    exit(2);
}

/**
 * A file is covered when it equals a `<file>` entry or sits under a
 * `<directory>` entry (PHPUnit `<directory>` discovery is recursive).
 *
 * @param array<int, array{type: string, path: string, suite: string, line: int}> $allowList
 */
$isCovered = static function (string $file, array $allowList): bool {
    foreach ($allowList as $entry) {
        if ($entry['type'] === 'file') {
            if ($file === $entry['path']) {
                return true;
            }
            continue;
        }
        $dir = rtrim($entry['path'], '/');
        if ($dir === '' || $file === $dir || str_starts_with($file, $dir . '/')) {
            return true;
        }
    }
    return false;
};

$testsDir = $configDir . '/tests';
if (!is_dir($testsDir)) {
    fwrite(STDERR, "check-test-suites: no tests/ directory under {$configDir}\n");
    exit(2);
}

/** @var list<string> $discovered */
$discovered = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    if (!str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }
    $discovered[] = ltrim(
        str_replace('\\', '/', substr($file->getPathname(), strlen($configDir))),
        '/'
    );
}
sort($discovered);

$uncovered = array_values(array_filter(
    $discovered,
    static fn (string $file): bool => !$isCovered($file, $allowList)
));

$stale = [];
foreach ($allowList as $entry) {
    $absolute = $configDir . '/' . $entry['path'];
    $exists = $entry['type'] === 'file' ? is_file($absolute) : is_dir($absolute);
    if (!$exists) {
        $stale[] = sprintf(
            '%s (suite "%s", %s:%d)',
            $entry['path'],
            $entry['suite'],
            basename($configPath),
            $entry['line']
        );
    }
}

if ($uncovered === [] && $stale === []) {
    printf(
        "Test suite coverage OK: %d test file(s) all covered by %d allow-list entries.\n",
        count($discovered),
        count($allowList)
    );
    exit(0);
}

if ($uncovered !== []) {
    fwrite(STDERR, "Test files not covered by any phpunit.xml testsuite:\n");
    foreach ($uncovered as $file) {
        fwrite(STDERR, "  - {$file}\n");
    }
    fwrite(STDERR, sprintf(
        "\n%d test file(s) would never run in CI. Add the file (or its directory) "
        . "to a <testsuite> in phpunit.xml.\n",
        count($uncovered)
    ));
}

if ($stale !== []) {
    fwrite(STDERR, "\nphpunit.xml allow-list entries that do not exist on disk:\n");
    foreach ($stale as $entry) {
        fwrite(STDERR, "  - {$entry}\n");
    }
    fwrite(STDERR, sprintf(
        "\n%d stale allow-list entry(ies). Fix the path or remove the entry.\n",
        count($stale)
    ));
}

exit(1);
