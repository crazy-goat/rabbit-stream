<?php
declare(strict_types=1);

$root = $argv[1] ?? 'docs/en';

if (!is_dir($root)) {
    fwrite(STDERR, "Usage: php bin/check-docs-links.php [docs-root]\n");
    exit(2);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$errors = [];
foreach ($files as $file) {
    if ($file->getExtension() !== 'md') {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    foreach ($lines as $lineno => $line) {
        if (!preg_match_all('/\[[^\]]+\]\(([^)#]+)\)/', $line, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            $target = $match[1];
            if (preg_match('#^[a-z][a-z0-9+.-]*://|^mailto:#', $target)) {
                continue;
            }
            $base = $file->getPath();
            $resolved = realpath($target[0] === '/' ? substr($target, 1) : $base . '/' . $target);
            if ($resolved === false || !file_exists($resolved)) {
                $errors[] = sprintf(
                    "%s:%d: %s -> %s\n",
                    $file->getPathname(),
                    $lineno + 1,
                    trim($line),
                    $target
                );
            }
        }
    }
}

if ($errors) {
    fwrite(STDERR, "Broken docs links:\n");
    fwrite(STDERR, implode("", $errors));
    fwrite(STDERR, "\n" . count($errors) . " broken link(s) found\n");
    exit(1);
}

echo "All relative links in {$root} resolve.\n";
exit(0);
