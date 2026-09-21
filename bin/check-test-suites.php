<?php
declare(strict_types=1);

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
 * Usage: php bin/check-test-suites.php [phpunit.xml]
 * Exit codes: 0 clean, 1 uncovered/stale paths, 2 usage error.
 *
 * Assumes the repository's conventions: tests live under `tests/`, are named
 * `*Test.php`, and the allow-lists are plain `<directory>`/`<file>` entries
 * (no `suffix`/`prefix` attributes). The `tests/` prefix is not hard-coded
 * beyond discovery; a suite entry covers whatever path it names.
 */

$root = dirname(__DIR__);
$configPath = $argv[1] ?? $root . '/phpunit.xml';

if (!is_file($configPath)) {
    fwrite(STDERR, "check-test-suites: phpunit config not found: {$configPath}\n");
    fwrite(STDERR, "Usage: php bin/check-test-suites.php [phpunit.xml]\n");
    exit(2);
}

$dom = new DOMDocument();
if (!@$dom->load($configPath)) {
    fwrite(STDERR, "check-test-suites: could not parse {$configPath}\n");
    exit(2);
}

$xpath = new DOMXPath($dom);

/** @var array<int, array{type: string, path: string, suite: string, line: int}> $allowList */
$allowList = [];
foreach ($xpath->query('/phpunit/testsuites/testsuite') as $suite) {
    if (!$suite instanceof DOMElement) {
        continue;
    }
    $suiteName = $suite->getAttribute('name');
    foreach ($xpath->query('directory|file', $suite) as $entry) {
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

$testsDir = $root . '/tests';
if (!is_dir($testsDir)) {
    fwrite(STDERR, "check-test-suites: no tests/ directory under {$root}\n");
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
        str_replace('\\', '/', substr($file->getPathname(), strlen($root))),
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
    $absolute = $root . '/' . $entry['path'];
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
