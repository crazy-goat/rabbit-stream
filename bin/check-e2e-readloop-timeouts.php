<?php

declare(strict_types=1);

$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false || !is_dir($root . '/tests/E2E')) {
    fwrite(STDERR, "Usage: php bin/check-e2e-readloop-timeouts.php [repository-root]\n");
    exit(2);
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/tests/E2E', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$errors = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        fwrite(STDERR, sprintf("Cannot read %s\n", $file));
        exit(2);
    }
    $path = substr($file, strlen($root) + 1);

    // Comments and whitespace are dropped, so only real code tokens remain.
    $tokens = array_values(array_filter(
        token_get_all($contents),
        static fn (mixed $token): bool => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || strcasecmp($token[1], 'readLoop') !== 0) {
            continue;
        }

        // Only method calls count: `->readLoop(` or `?->readLoop(`.
        $previous = $tokens[$i - 1] ?? null;
        if (!is_array($previous) || !in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        // Find the named `timeout:` argument at the top level of the argument list.
        $hasTimeout = false;
        $timeoutValue = null;
        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $current = $tokens[$j];
            if (in_array($current, ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }
            if (in_array($current, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            $isNamedTimeout = is_array($current) && $current[0] === T_STRING && $current[1] === 'timeout';
            if ($depth === 1 && $isNamedTimeout && ($tokens[$j + 1] ?? null) === ':') {
                $hasTimeout = true;
                $timeoutValue = $tokens[$j + 2] ?? null;
            }
        }

        // `timeout: null` means no deadline, the same as an omitted argument.
        $isNullValue = is_array($timeoutValue) && $timeoutValue[0] === T_STRING
            && strcasecmp($timeoutValue[1], 'null') === 0;
        if (!$hasTimeout || $isNullValue) {
            $errors[] = sprintf('%s:%d: readLoop() call without a timeout: argument', $path, $token[2]);
        }
    }
}

if ($errors !== []) {
    fwrite(
        STDERR,
        "E2E readLoop() calls without a bounded timeout found (a hang blocks the CI job):\n"
        . implode("\n", $errors) . "\n"
    );
    exit(1);
}

echo "All readLoop() calls in tests/E2E pass a timeout.\n";
exit(0);
