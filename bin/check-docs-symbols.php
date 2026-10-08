<?php

/**
 * Check project class references and constructor named arguments in docs PHP fences.
 *
 * Usage: php bin/check-docs-symbols.php [docs-root]
 * Mark an intentionally illustrative PHP fence with `<!-- docs-lint: ignore -->`
 * immediately before its opening fence, or add `docs-lint-ignore` to the fence tag.
 */

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__);
$docsRoot = $argv[1] ?? $repositoryRoot . '/docs/en';
$sourceRoot = $repositoryRoot . '/src';
$autoload = $repositoryRoot . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

if (!is_dir($docsRoot)) {
    fwrite(STDERR, "Usage: php bin/check-docs-symbols.php [docs-root]\n");
    exit(2);
}

/** @var array<string, array{file: string, parameters: list<string>, parent: ?string, constructorDeclared: bool}> $classes */
$classes = [];
/** @var array<string, true> $sourceDirectories */
$sourceDirectories = ['CrazyGoat\\RabbitStream\\Tests' => true];
$sourceFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($sourceFiles as $sourceFile) {
    if (
        !$sourceFile instanceof SplFileInfo
        || !$sourceFile->isFile()
        || $sourceFile->getExtension() !== 'php'
    ) {
        continue;
    }

    $source = file_get_contents($sourceFile->getPathname());
    if ($source === false) {
        continue;
    }

    $tokens = token_get_all($source);
    $namespace = '';
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_NAMESPACE) {
            continue;
        }
        $namespaceParts = [];
        for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
            $part = $tokens[$cursor];
            if ($part === ';' || $part === '{') {
                break;
            }
            if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespaceParts[] = $part[1];
            }
        }
        $namespace = implode('\\', $namespaceParts);
        break;
    }

    /** @var array<string, array{parameters: list<string>, parent: ?string, constructorDeclared: bool}> $classNames */
    $classNames = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }
        if ($token[0] === T_CLASS) {
            $previous = $index - 1;
            while (
                $previous >= 0
                && is_array($tokens[$previous])
                && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                $previous--;
            }
            if (
                $previous >= 0
                && is_array($tokens[$previous])
                && in_array($tokens[$previous][0], [T_NEW, T_DOUBLE_COLON], true)
            ) {
                continue;
            }
        }

        $nameIndex = $index + 1;
        while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] !== T_STRING) {
            $nameIndex++;
        }
        if (!isset($tokens[$nameIndex]) || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
            continue;
        }

        $className = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$nameIndex][1];
        $classNames[$className] = ['parameters' => [], 'parent' => null, 'constructorDeclared' => false];
        for ($parentIndex = $nameIndex + 1; isset($tokens[$parentIndex]); $parentIndex++) {
            $parentToken = $tokens[$parentIndex];
            if ($parentToken === '{') {
                break;
            }
            if (is_array($parentToken) && $parentToken[0] === T_EXTENDS) {
                $parentName = '';
                for ($nameCursor = $parentIndex + 1; isset($tokens[$nameCursor]); $nameCursor++) {
                    $parentPart = $tokens[$nameCursor];
                    if (is_array($parentPart) && $parentPart[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (
                        !is_array($parentPart)
                        || !in_array($parentPart[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    ) {
                        break;
                    }
                    $parentName .= $parentPart[1];
                }
                $classNames[$className]['parent'] = str_starts_with($parentName, '\\')
                    ? ltrim($parentName, '\\')
                    : ($namespace === '' ? $parentName : $namespace . '\\' . $parentName);
                break;
            }
        }

        // Locate the class body and collect constructor parameter names.
        $bodyStart = $nameIndex;
        while (isset($tokens[$bodyStart]) && $tokens[$bodyStart] !== '{') {
            $bodyStart++;
        }
        if (!isset($tokens[$bodyStart])) {
            continue;
        }
        $depth = 1;
        for ($cursor = $bodyStart + 1; isset($tokens[$cursor]) && $depth > 0; $cursor++) {
            $part = $tokens[$cursor];
            if ($part === '{') {
                $depth++;
                continue;
            }
            if ($part === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 1 || !is_array($part) || $part[0] !== T_FUNCTION) {
                continue;
            }

            $functionIndex = $cursor + 1;
            while (
                isset($tokens[$functionIndex])
                && is_array($tokens[$functionIndex])
                && in_array(
                    $tokens[$functionIndex][0],
                    [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG],
                    true
                )
            ) {
                $functionIndex++;
            }
            if (
                !isset($tokens[$functionIndex])
                || !is_array($tokens[$functionIndex])
                || $tokens[$functionIndex][0] !== T_STRING
                || strtolower($tokens[$functionIndex][1]) !== '__construct'
            ) {
                continue;
            }

            $openParen = $functionIndex + 1;
            while (isset($tokens[$openParen]) && $tokens[$openParen] !== '(') {
                $openParen++;
            }
            $classNames[$className]['constructorDeclared'] = true;
            $parameterTokens = [];
            $parameterDepth = 1;
            for ($paramIndex = $openParen + 1; isset($tokens[$paramIndex]) && $parameterDepth > 0; $paramIndex++) {
                $part = $tokens[$paramIndex];
                if ($part === '(') {
                    $parameterDepth++;
                } elseif ($part === ')') {
                    $parameterDepth--;
                }
                if ($parameterDepth > 0) {
                    $parameterTokens[] = $part;
                }
            }

            $segments = [];
            $segment = [];
            $parenDepth = 0;
            $bracketDepth = 0;
            $braceDepth = 0;
            foreach ($parameterTokens as $part) {
                if ($part === ',' && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                    $segments[] = $segment;
                    $segment = [];
                    continue;
                }
                if ($part === '(') {
                    $parenDepth++;
                } elseif ($part === ')') {
                    $parenDepth--;
                } elseif ($part === '[') {
                    $bracketDepth++;
                } elseif ($part === ']') {
                    $bracketDepth--;
                } elseif ($part === '{') {
                    $braceDepth++;
                } elseif ($part === '}') {
                    $braceDepth--;
                }
                $segment[] = $part;
            }
            if ($segment !== []) {
                $segments[] = $segment;
            }
            foreach ($segments as $segment) {
                foreach ($segment as $part) {
                    if (is_array($part) && $part[0] === T_VARIABLE) {
                        $classNames[$className]['parameters'][] = substr($part[1], 1);
                        break;
                    }
                }
            }
            break;
        }
    }

    foreach ($classNames as $className => $class) {
        $classes[$className] = [
            'file' => $sourceFile->getPathname(),
            'parameters' => $class['parameters'],
            'parent' => $class['parent'],
            'constructorDeclared' => $class['constructorDeclared'],
        ];
    }

    $relativeDirectory = substr($sourceFile->getPath(), strlen($sourceRoot));
    while ($relativeDirectory !== '' && $relativeDirectory[0] === DIRECTORY_SEPARATOR) {
        $relativeDirectory = substr($relativeDirectory, 1);
    }
    $directoryNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $relativeDirectory);
    $namespace = $directoryNamespace === ''
        ? 'CrazyGoat\\RabbitStream'
        : 'CrazyGoat\\RabbitStream\\' . $directoryNamespace;
    $sourceDirectories[$namespace] = true;
}

foreach ($classes as $className => $class) {
    if (class_exists($className)) {
        $constructor = (new ReflectionClass($className))->getConstructor();
        $classes[$className]['parameters'] = $constructor === null
            ? []
            : array_map(
                static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters()
            );
        continue;
    }

    $parent = $class['parent'];
    while (is_string($parent) && isset($classes[$parent])) {
        $classes[$className]['parameters'] = array_values(array_unique(array_merge(
            $classes[$className]['parameters'],
            $classes[$parent]['parameters']
        )));
        $parent = $classes[$parent]['parent'];
    }
}

$errors = [];
$markdownFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($markdownFiles as $markdownFile) {
    if (
        !$markdownFile instanceof SplFileInfo
        || !$markdownFile->isFile()
        || $markdownFile->getExtension() !== 'md'
    ) {
        continue;
    }
    $markdown = file_get_contents($markdownFile->getPathname());
    if ($markdown === false) {
        continue;
    }

    $relativePath = str_starts_with($markdownFile->getPathname(), $repositoryRoot . DIRECTORY_SEPARATOR)
        ? ltrim(substr($markdownFile->getPathname(), strlen($repositoryRoot)), DIRECTORY_SEPARATOR)
        : ltrim(substr($markdownFile->getPathname(), strlen($docsRoot)), DIRECTORY_SEPARATOR);
    $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
    $fence = null;
    $fenceStart = 0;
    $fenceText = [];
    $ignoreNextFence = false;
    $checkFence = static function (
        array $block,
        int $startLine,
        string $path,
        array &$errors,
        array $classes,
        array $sourceDirectories
    ): void {
        $code = implode("\n", $block);
        $knownNamespace = static function (string $name) use ($sourceDirectories): bool {
            if (isset($sourceDirectories[$name])) {
                return true;
            }
            foreach ($sourceDirectories as $directory => $_) {
                if (str_starts_with($directory, $name . '\\')) {
                    return true;
                }
            }
            return false;
        };
        $imports = [];
        if (
            preg_match_all(
                '/^\s*use\s+(?!function\b|const\b)([^;]+);/mi',
                $code,
                $useMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )
        ) {
            foreach ($useMatches as $useMatch) {
                $declaration = trim($useMatch[1][0]);
                if (
                    str_contains($declaration, '{')
                    || str_contains($declaration, ',')
                ) {
                    continue;
                }
                if (
                    preg_match(
                        '/^([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/i',
                        $declaration,
                        $parts
                    )
                ) {
                    $fqcn = ltrim($parts[1], '\\');
                    $alias = $parts[2] ?? substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
                    $imports[strtolower($alias)] = $fqcn;
                    if (
                        str_starts_with($fqcn, 'CrazyGoat\\RabbitStream\\')
                        && !isset($classes[$fqcn])
                        && !$knownNamespace($fqcn)
                    ) {
                        $line = $startLine + substr_count(substr($code, 0, $useMatch[0][1]), "\n");
                        $errors[] = sprintf('%s:%d: unknown class %s', $path, $line, $fqcn);
                    }
                }
            }
        }

        if (
            preg_match_all(
                '/\\\\?CrazyGoat\\\\RabbitStream(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/',
                $code,
                $symbolMatches,
                PREG_OFFSET_CAPTURE
            )
        ) {
            foreach ($symbolMatches[0] as [$symbol, $offset]) {
                $fqcn = ltrim($symbol, '\\');
                if (isset($classes[$fqcn]) || $knownNamespace($fqcn)) {
                    continue;
                }
                $line = $startLine + substr_count(substr($code, 0, $offset), "\n");
                $errors[] = sprintf('%s:%d: unknown class %s', $path, $line, $fqcn);
            }
        }

        if (
            !preg_match_all(
                '/\bnew\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(/',
                $code,
                $constructors,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )
        ) {
            return;
        }
        foreach ($constructors as $constructor) {
            $classToken = $constructor[1][0];
            $offset = $constructor[1][1];
            if (str_starts_with($classToken, '\\')) {
                $fqcn = ltrim($classToken, '\\');
            } elseif (str_contains($classToken, '\\')) {
                $fqcn = 'CrazyGoat\\RabbitStream\\' . $classToken;
            } else {
                $fqcn = $imports[strtolower($classToken)] ?? '';
            }
            if ($fqcn === '' || !isset($classes[$fqcn])) {
                continue;
            }

            $open = strpos($code, '(', $offset + strlen($classToken));
            if ($open === false) {
                continue;
            }
            $depth = 1;
            $quote = '';
            $escaped = false;
            $argument = '';
            $arguments = [];
            $argumentOffset = $open + 1;
            for ($cursor = $open + 1, $length = strlen($code); $cursor < $length && $depth > 0; $cursor++) {
                $character = $code[$cursor];
                if ($quote !== '') {
                    $argument .= $character;
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ($character === "'" || $character === '"' || $character === '`') {
                    $quote = $character;
                    $argument .= $character;
                } elseif ($character === '(' || $character === '[' || $character === '{') {
                    $depth++;
                    $argument .= $character;
                } elseif ($character === ')' && $depth === 1) {
                    if (trim($argument) !== '') {
                        $arguments[] = ['text' => $argument, 'offset' => $argumentOffset];
                    }
                    break;
                } elseif ($character === ')' || $character === ']' || $character === '}') {
                    $depth--;
                    $argument .= $character;
                } elseif ($character === ',' && $depth === 1) {
                    $arguments[] = ['text' => $argument, 'offset' => $argumentOffset];
                    $argument = '';
                    $argumentOffset = $cursor + 1;
                } else {
                    $argument .= $character;
                }
            }

            foreach ($arguments as $argument) {
                if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)/', $argument['text'], $namedArgument)) {
                    continue;
                }
                if (in_array($namedArgument[1], $classes[$fqcn]['parameters'], true)) {
                    continue;
                }
                $line = $startLine + substr_count(substr($code, 0, $argument['offset']), "\n");
                $errors[] = sprintf('%s:%d: unknown named argument %s for %s', $path, $line, $namedArgument[1], $fqcn);
            }
        }
    };

    foreach ($lines as $lineIndex => $line) {
        if ($fence === null) {
            if (preg_match_all('/\\\\?CrazyGoat\\\\RabbitStream(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $line, $symbols)) {
                foreach ($symbols[0] as $symbol) {
                    $fqcn = rtrim(ltrim($symbol, '\\'), '\\');
                    $isNamespace = isset($sourceDirectories[$fqcn]);
                    if (!$isNamespace) {
                        foreach ($sourceDirectories as $directory => $_) {
                            if (str_starts_with($directory, $fqcn . '\\')) {
                                $isNamespace = true;
                                break;
                            }
                        }
                    }
                    if (isset($classes[$fqcn]) || $isNamespace) {
                        continue;
                    }
                    $errors[] = sprintf('%s:%d: unknown class %s', $relativePath, $lineIndex + 1, $fqcn);
                }
            }
            if (trim($line) === '<!-- docs-lint: ignore -->') {
                $ignoreNextFence = true;
                continue;
            }
            if (preg_match('/^\s*(```+|~~~+)\s*([^\s`]*)/', $line, $matches)) {
                $tag = strtolower($matches[2]);
                $fence = $matches[1];
                $fenceStart = $lineIndex + 2;
                $fenceText = [];
                $ignore = $ignoreNextFence || str_contains($tag, 'docs-lint-ignore');
                $ignoreNextFence = false;
                if ($ignore) {
                    $fence = 'ignored:' . $fence;
                }
            } else {
                $ignoreNextFence = false;
            }
            continue;
        }
        if (preg_match('/^\s*(```+|~~~+)\s*$/', $line, $matches)) {
            if (!str_starts_with($fence, 'ignored:')) {
                $tagLine = $lines[$fenceStart - 2] ?? '';
                if (preg_match('/^\s*(```+|~~~+)\s*php\b/i', $tagLine)) {
                    $checkFence($fenceText, $fenceStart, $relativePath, $errors, $classes, $sourceDirectories);
                }
            }
            $fence = null;
            $fenceText = [];
            continue;
        }
        $fenceText[] = $line;
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Documentation symbol errors:\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}

echo "All class references and named constructor arguments in PHP docs fences resolve.\n";
exit(0);
