<?php

/**
 * Check documented command-key literals and logged protocol frames.
 *
 * Usage: php bin/check-docs-frame-keys.php [repository-root]
 * Exit codes: 0 clean, 1 documentation errors, 2 usage/configuration error.
 */

declare(strict_types=1);

$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false || !is_dir($root . '/docs') || !is_file($root . '/src/Enum/KeyEnum.php')) {
    fwrite(STDERR, "Usage: php bin/check-docs-frame-keys.php [repository-root]\n");
    exit(2);
}

$enumSource = file_get_contents($root . '/src/Enum/KeyEnum.php');
if (
    $enumSource === false
    || !preg_match_all(
        '/case\s+([A-Z0-9_]+)\s*=\s*(0x[0-9a-fA-F]{4})\s*;/i',
        $enumSource,
        $enumMatches,
        PREG_SET_ORDER
    )
) {
    fwrite(STDERR, "check-docs-frame-keys: could not read command keys from src/Enum/KeyEnum.php\n");
    exit(2);
}

/** @var array<string, string> $keys key value => enum case */
$keys = [];
/** @var array<string, string> $keyByCase enum case => key value */
$keyByCase = [];
foreach ($enumMatches as $match) {
    $key = strtolower($match[2]);
    $keys[$key] = $match[1];
    $keyByCase[$match[1]] = $key;
}

/** @var array<string, bool> $correlationByKey key value => whether its class has CorrelationInterface */
$correlationByKey = [];
foreach (['Request', 'Response'] as $directory) {
    $classFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($classFiles as $classFile) {
        if (!$classFile instanceof SplFileInfo || !$classFile->isFile() || $classFile->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($classFile->getPathname());
        if ($source === false) {
            continue;
        }
        if (
            !preg_match(
                '/function\s+getKey\s*\([^)]*\)\s*:\s*int\s*\{[^}]*KeyEnum::([A-Z0-9_]+)/s',
                $source,
                $keyMatch
            )
        ) {
            continue;
        }
        if (!preg_match('/\bclass\s+[A-Za-z0-9_]+\b([^{}]*)\{/s', $source, $classMatch)) {
            continue;
        }
        $correlated = str_contains($source, 'CorrelationInterface')
            || str_contains($source, 'SimpleCorrelatedResponseV1');
        $key = $keyByCase[$keyMatch[1]] ?? null;
        if ($key !== null) {
            $correlationByKey[$key] = $correlated;
        }
    }
}

$docsFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS)
);
$errors = [];
foreach ($docsFiles as $docsFile) {
    if (!$docsFile instanceof SplFileInfo || !$docsFile->isFile() || $docsFile->getExtension() !== 'md') {
        continue;
    }
    $content = file_get_contents($docsFile->getPathname());
    if ($content === false) {
        continue;
    }
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $relativePath = ltrim(str_replace('\\', '/', substr($docsFile->getPathname(), strlen($root))), '/');
    $insideCommandKeyTable = false;
    $isTableSeparator = false;

    foreach ($lines as $lineNumber => $line) {
        $lineNo = $lineNumber + 1;
        $isTableLine = str_contains($line, '|');
        $isTableSeparator = $isTableLine
            && preg_match('/^\|?\s*(?::?-{3,}:?\s*\|)+\s*$/', $line) === 1;
        if ($isTableLine && !$isTableSeparator) {
            $headerCells = array_map(
                static function (string $cell): string {
                    $cell = strtolower(trim(strip_tags($cell), " `*_"));
                    $cell = preg_replace('/[^a-z0-9 ]/', '', $cell) ?? $cell;

                    return trim(preg_replace('/\s+/', ' ', $cell) ?? $cell);
                },
                explode('|', trim($line, "| \t"))
            );
            $headerCells = array_values(array_filter($headerCells, static fn (string $cell): bool => $cell !== ''));
            $hasKeyColumn = false;
            $hasCaseColumn = false;
            $hasHexColumn = false;
            $hasCommandColumn = false;
            $hasRequestColumn = false;
            $hasResponseColumn = false;
            $isResponseCodeTableHeader = false;
            foreach ($headerCells as $cell) {
                $isKeyColumn = preg_match(
                    '/^(?:(?:request|response) )?key(?: (?:hex|value))?$/',
                    $cell
                ) === 1;
                $hasKeyColumn = $hasKeyColumn || $isKeyColumn;
                $hasCaseColumn = $hasCaseColumn || $cell === 'case';
                $hasHexColumn = $hasHexColumn || $cell === 'hex';
                $hasCommandColumn = $hasCommandColumn || $cell === 'command';
                $hasRequestColumn = $hasRequestColumn || $cell === 'request';
                $hasResponseColumn = $hasResponseColumn || $cell === 'response';
                $isResponseCodeTableHeader = $isResponseCodeTableHeader || $cell === 'code';
            }
            $isKeyTableHeader = $hasKeyColumn
                || ($hasCaseColumn && $hasHexColumn)
                || ($hasCommandColumn && $hasRequestColumn && $hasResponseColumn);
            if ($isKeyTableHeader) {
                $insideCommandKeyTable = true;
            } elseif ($isResponseCodeTableHeader && !$hasCommandColumn) {
                // Response-code tables (Code | Name) are not command-key tables.
                $insideCommandKeyTable = false;
            }
        } elseif (!$isTableSeparator) {
            // A line without a pipe is never a table row, so the table ends here.
            $insideCommandKeyTable = false;
        }
        $isCommandKeyTableRow = $insideCommandKeyTable;
        $ignoredKeys = [];
        $ignoreUnknownKeys = false;
        $isIgnoredLine = preg_match(
            '/<!--\s*docs-frame-keys:\s*ignore(?:\s+(0x[0-9a-fA-F]{4}))?\s*-->/',
            $line,
            $ignoreMatch
        ) === 1;
        if ($isIgnoredLine) {
            if (isset($ignoreMatch[1])) {
                $ignoredKeys[] = strtolower($ignoreMatch[1]);
            } else {
                // An unqualified marker opts out of unknown-key detection for the whole line.
                $ignoreUnknownKeys = true;
            }
        }

        /** @var list<array{0: int, 1: int}> $keyRanges */
        $keyRanges = [];
        if (
            preg_match_all(
                '/(0x[0-9a-fA-F]{4})\s*[-–—]\s*(0x[0-9a-fA-F]{4})/',
                $line,
                $rangeMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )
        ) {
            foreach ($rangeMatches as $rangeMatch) {
                $start = hexdec(substr($rangeMatch[1][0], 2));
                $end = hexdec(substr($rangeMatch[2][0], 2));
                $keyRanges[] = [min($start, $end), max($start, $end)];
            }
        }

        if (preg_match('/Socket\s*(?:->|<-)/', $line)) {
            $arrow = preg_match('/Socket\s*->/', $line) === 1 ? '->' : '<-';
            if (preg_match('/Socket\s*' . preg_quote($arrow, '/') . '\s*([0-9a-fA-F]+)/', $line, $dumpMatch)) {
                $hex = $dumpMatch[1];
                $dumpError = null;
                $bytes = false;
                if (strlen($hex) % 2 !== 0) {
                    $dumpError = "{$arrow} hex dump has an odd number of digits";
                } else {
                    $bytes = hex2bin($hex);
                    if ($bytes === false) {
                        $dumpError = "{$arrow} hex dump is not valid hexadecimal";
                    } elseif ($arrow === '->' && strlen($bytes) < 4) {
                        $dumpError = 'outgoing frame is shorter than its four-byte size field';
                    } elseif ($arrow === '->') {
                        $sizeField = unpack('Nsize', substr($bytes, 0, 4));
                        if ($sizeField === false) {
                            $dumpError = 'outgoing frame has an invalid four-byte size field';
                        } elseif ($sizeField['size'] !== strlen($bytes) - 4) {
                            $dumpError = sprintf(
                                'outgoing size field declares %d bytes; %d bytes follow the size field',
                                $sizeField['size'],
                                strlen($bytes) - 4
                            );
                        }
                    }
                }

                $keyOffset = $arrow === '->' ? 4 : 0;
                if ($dumpError === null && is_string($bytes) && strlen($bytes) < $keyOffset + 4) {
                    $dumpError = "{$arrow} frame is shorter than its key and version fields";
                }
                if ($dumpError === null && is_string($bytes)) {
                    $keyField = unpack('nkey', substr($bytes, $keyOffset, 2));
                    $key = $keyField === false ? '' : sprintf('0x%04x', $keyField['key']);
                    if (!isset($keys[$key])) {
                        $dumpError = "{$arrow} frame contains unknown command key {$key}";
                    } elseif (!array_key_exists($key, $correlationByKey)) {
                        $dumpError = "{$arrow} frame key {$key} has no request/response class correlation metadata";
                    } else {
                        $headerLength = $keyOffset + 4 + ($correlationByKey[$key] ? 4 : 0);
                        if (strlen($bytes) < $headerLength) {
                            $expected = $correlationByKey[$key]
                                ? 'a four-byte CorrelationId after the key and version fields'
                                : 'the key and version fields (no CorrelationId)';
                            $dumpError = "{$arrow} frame key {$key} requires {$expected}";
                        }
                    }
                }
                if ($dumpError !== null) {
                    $errors[] = sprintf('%s:%d: %s (%s)', $relativePath, $lineNo, $dumpError, trim($line));
                }
            }
        }

        if ($ignoreUnknownKeys) {
            continue;
        }

        if (
            !preg_match_all(
                '/(?<![A-Za-z0-9])0x([0-9a-fA-F]{4})(?![a-fA-F0-9])/',
                $line,
                $tokenMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )
        ) {
            continue;
        }
        foreach ($tokenMatches as $tokenMatch) {
            $token = strtolower('0x' . $tokenMatch[1][0]);
            $tokenValue = hexdec($tokenMatch[1][0]);
            $tokenOffset = $tokenMatch[0][1];
            $tokenContext = substr($line, max(0, $tokenOffset - 45), strlen($tokenMatch[0][0]) + 90);
            $inRange = false;
            foreach ($keyRanges as [$rangeStart, $rangeEnd]) {
                if ($tokenValue >= $rangeStart && $tokenValue <= $rangeEnd) {
                    $inRange = true;
                    break;
                }
            }
            if (in_array($token, $ignoredKeys, true) || $inRange || isset($keys[$token])) {
                continue;
            }
            // 0x8000 is a bit-mask, never a command key.
            if ($token === '0x8000') {
                continue;
            }

            $hasKeyContext = preg_match(
                '/\b(?:command|frame|request|response)\s+key\b|\bkey\s*[:=]|\bkey\s+(?:such\s+as|of|is)\b/i',
                $tokenContext
            ) === 1;
            $looksLikeNamedCommand = preg_match(
                '/\b[A-Z][A-Za-z0-9]*(?:_[A-Z0-9]+)*\s*\(\s*$/',
                substr($line, 0, $tokenOffset)
            ) === 1;
            $looksLikeKey = $hasKeyContext || $looksLikeNamedCommand || $isCommandKeyTableRow
                || preg_match(
                    '/^\s*=\s*[A-Z][A-Z0-9_]+\b/',
                    substr($line, $tokenOffset + strlen($tokenMatch[0][0]))
                ) === 1;
            if (!$looksLikeKey) {
                continue;
            }
            $errors[] = sprintf(
                '%s:%d: unknown command key %s (%s)',
                $relativePath,
                $lineNo,
                $token,
                trim($line)
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Documentation frame/key errors:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

printf("Documentation frame/key check passed (%d command keys).\n", count($keys));
exit(0);
