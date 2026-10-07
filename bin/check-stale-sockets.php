<?php

declare(strict_types=1);

$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false || !is_dir($root . '/src') || !is_dir($root . '/docs/en')) {
    fwrite(STDERR, "Usage: php bin/check-stale-sockets.php [repository-root]\n");
    exit(2);
}

$forbiddenTokens = [
    'socket_recv',
    'socket_write',
    'MSG_WAITALL',
    'SO_RCVTIMEO',
    'SO_SNDTIMEO',
    'socket_last_error',
];
$pattern = '/\\b(?:' . implode('|', array_map(
    static fn (string $token): string => preg_quote($token, '/'),
    $forbiddenTokens
)) . ')\\b/';
$errors = [];

foreach (['src', 'docs/en'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = substr($file->getPathname(), strlen($root) + 1);
        if ($directory === 'docs/en' && preg_match('#^docs/en/(?:proof_of_work|plans)(?:/|$)#', $path)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || !preg_match($pattern, $contents)) {
            continue;
        }

        foreach (explode("\n", str_replace("\r\n", "\n", $contents)) as $lineNumber => $line) {
            if (preg_match_all($pattern, $line, $matches)) {
                foreach ($matches[0] as $match) {
                    $errors[] = sprintf('%s:%d: forbidden token %s', $path, $lineNumber + 1, $match);
                }
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Stale ext-sockets references found:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "No stale ext-sockets references found in src/ or docs/en/.\n";
exit(0);
