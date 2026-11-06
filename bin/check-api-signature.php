<?php

/**
 * Compares the public API of src/ with the newest release tag (#918).
 *
 * Usage: php bin/check-api-signature.php [repository-root]
 *
 * The tag and the working tree are dumped in two child processes, because
 * both trees use the same class names and PHP cannot load them twice in one
 * process. A dump line is "<key>\t<value>". Keys:
 *   T|Class                 type: kind, final, abstract, parent
 *   I|Class|Interface       implemented interface (inherited ones included)
 *   M|Class::method         public method: flags, return type, parameters
 *   P|Class::$property      public property: static, readonly, type, default
 *   C|Class::CONST          public constant: final, value
 *   E|Class::Case           enum case: backing value
 *
 * Exit codes: 0 no unallowed break, 1 unallowed break, 2 usage, git or tag error.
 */

declare(strict_types=1);

const API_NAMESPACE_PREFIX = 'CrazyGoat\\RabbitStream\\';
const API_ALLOW_LIST = 'api-breaks.txt';

exit(runApiSignatureCheck($argv ?? []));

/** @param list<string> $argv */
function runApiSignatureCheck(array $argv): int
{
    if (($argv[1] ?? '') === '--dump') {
        return dumpSource($argv[2] ?? '', $argv[3] ?? '');
    }

    $root = realpath($argv[1] ?? dirname(__DIR__));
    if ($root === false || !is_dir($root . '/src')) {
        fwrite(STDERR, "Usage: php bin/check-api-signature.php [repository-root]\n");
        return 2;
    }

    $tag = newestReleaseTag($root);
    if ($tag === null) {
        fwrite(
            STDERR,
            "No release tag (vX.Y.Z) is reachable from HEAD, so the API cannot be compared.\n"
            . "Fetch the full history and the tags first, for example:\n"
            . "  git fetch --tags --unshallow\n"
            . "In GitHub Actions, use actions/checkout with fetch-depth: 0.\n"
        );
        return 2;
    }

    $workDir = sys_get_temp_dir() . '/api-signature-' . bin2hex(random_bytes(8));
    if (!mkdir($workDir, 0777, true)) {
        fwrite(STDERR, "Could not create a temporary directory.\n");
        return 2;
    }

    try {
        if (!exportTagSource($root, $tag, $workDir)) {
            fwrite(STDERR, sprintf("Could not export src/ of tag %s with git archive.\n", $tag));
            return 2;
        }

        $previous = dumpOf($workDir . '/src', $root);
        $current = dumpOf($root . '/src', $root);
        if ($previous === null || $current === null) {
            return 2;
        }
    } finally {
        removeDirectory($workDir);
    }

    $breaks = findBreaks($previous, $current);
    $allowList = readAllowList($root . '/' . API_ALLOW_LIST);

    $failures = 0;
    $allowed = 0;
    foreach ($breaks as $key => $message) {
        if (array_key_exists($key, $allowList)) {
            $allowed++;
            echo sprintf("ALLOWED %s (%s)\n", $message, $allowList[$key]);
            continue;
        }
        $failures++;
        fwrite(STDERR, sprintf("BREAK %s\n", $message));
    }

    foreach (array_keys($allowList) as $key) {
        if (!array_key_exists($key, $breaks)) {
            fwrite(STDERR, sprintf("WARNING stale allow-list entry %s: no longer reported\n", $key));
        }
    }

    echo sprintf(
        "API signature check against %s: %d break(s), %d allowed.\n",
        $tag,
        $failures,
        $allowed
    );

    return $failures === 0 ? 0 : 1;
}

/** Returns the newest vX.Y.Z tag that is an ancestor of HEAD, or null. */
function newestReleaseTag(string $root): ?string
{
    $output = [];
    exec(
        sprintf(
            'git -C %s tag --merged HEAD --list %s --sort=-v:refname',
            escapeshellarg($root),
            escapeshellarg('v*')
        ),
        $output,
        $exitCode
    );
    if ($exitCode !== 0) {
        return null;
    }

    foreach ($output as $tag) {
        if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1) {
            return $tag;
        }
    }

    return null;
}

function exportTagSource(string $root, string $tag, string $workDir): bool
{
    $archive = $workDir . '/src.tar';
    $output = [];
    exec(
        sprintf(
            'git -C %s archive --format=tar --output=%s %s src',
            escapeshellarg($root),
            escapeshellarg($archive),
            escapeshellarg($tag)
        ),
        $output,
        $archiveExit
    );
    if ($archiveExit !== 0) {
        return false;
    }

    exec(sprintf('tar -xf %s -C %s', escapeshellarg($archive), escapeshellarg($workDir)), $output, $tarExit);

    return $tarExit === 0 && is_dir($workDir . '/src');
}

/**
 * Runs the dump in a child process and parses its lines.
 *
 * @return array<string, string>|null
 */
function dumpOf(string $srcDir, string $root): ?array
{
    $output = [];
    exec(
        sprintf(
            '%s %s --dump %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            escapeshellarg($srcDir),
            escapeshellarg($root)
        ),
        $output,
        $exitCode
    );
    if ($exitCode !== 0) {
        fwrite(STDERR, sprintf("Could not dump the API of %s\n", $srcDir));
        return null;
    }

    $dump = [];
    foreach ($output as $line) {
        $parts = explode("\t", $line, 2);
        if (count($parts) === 2) {
            $dump[$parts[0]] = $parts[1];
        }
    }

    return $dump;
}

/**
 * Returns one message for each break, keyed by the dump key.
 *
 * A removed or changed line is a break. An added line is a break only when a
 * class must implement it: an abstract method or an interface method. Added
 * classes, constants, properties, enum cases, optional methods and
 * interfaces on a class do not break.
 *
 * @param array<string, string> $previous
 * @param array<string, string> $current
 * @return array<string, string>
 */
function findBreaks(array $previous, array $current): array
{
    $breaks = [];
    foreach ($previous as $key => $value) {
        if (!array_key_exists($key, $current)) {
            $breaks[$key] = sprintf('removed %s (was %s)', $key, $value);
        } elseif ($current[$key] !== $value && !isCompatibleConstructor($key, $value, $current[$key])) {
            $breaks[$key] = sprintf('changed %s: %s -> %s', $key, $value, $current[$key]);
        }
    }

    foreach ($current as $key => $value) {
        if (array_key_exists($key, $previous) || !str_starts_with($key, 'M|') || !str_contains($value, 'abstract=1')) {
            continue;
        }
        // A new type has no implementers yet, so only a type that existed in the tag can break.
        if (!array_key_exists('T|' . methodOwner($key), $previous)) {
            continue;
        }
        $breaks[$key] = sprintf('added required member %s: %s', $key, $value);
    }

    ksort($breaks);

    return $breaks;
}

/**
 * A constructor may gain optional parameters at the end. Nothing else may change:
 * the flags, the return type, the existing parameters (name, type, default, by-ref,
 * variadic) and their order. PHP does not check constructors for LSP, and a new
 * optional argument does not break a caller that uses positional or named arguments.
 */
function isCompatibleConstructor(string $key, string $previous, string $current): bool
{
    if (!str_starts_with($key, 'M|') || !str_ends_with($key, '::__construct')) {
        return false;
    }

    [$previousHead, $previousParams] = splitMethodValue($previous);
    [$currentHead, $currentParams] = splitMethodValue($current);
    if ($previousHead !== $currentHead || str_contains($previousHead, 'abstract=1')) {
        return false;
    }

    $oldParameters = decodeParameters($previousParams);
    $newParameters = decodeParameters($currentParams);
    if ($oldParameters === null || $newParameters === null || count($newParameters) < count($oldParameters)) {
        return false;
    }

    foreach ($oldParameters as $index => $parameter) {
        if ($newParameters[$index]['signature'] !== $parameter['signature']) {
            return false;
        }
    }

    foreach (array_slice($newParameters, count($oldParameters)) as $parameter) {
        if (!$parameter['optional']) {
            return false;
        }
    }

    return true;
}

/** Returns the class name of a method key "M|Class::method". */
function methodOwner(string $key): string
{
    $position = strrpos($key, '::');

    return $position === false ? '' : substr($key, 2, $position - 2);
}

/** @return array{string, string} the flags and the parameter list of a method value */
function splitMethodValue(string $value): array
{
    $parts = explode(';params=', $value, 2);

    return [$parts[0], $parts[1] ?? ''];
}

/** @return list<array{signature: string, optional: bool}>|null */
function decodeParameters(string $json): ?array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    $parameters = [];
    foreach ($decoded as $item) {
        if (
            !is_array($item)
            || !isset($item['signature'], $item['optional'])
            || !is_string($item['signature'])
            || !is_bool($item['optional'])
        ) {
            return null;
        }
        $parameters[] = ['signature' => $item['signature'], 'optional' => $item['optional']];
    }

    return $parameters;
}

/**
 * Reads "<key> <reason>" lines. Blank lines and lines starting with "#" are skipped.
 *
 * @return array<string, string> key => reason
 */
function readAllowList(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $entries = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line, 2) ?: [];
        $entries[$parts[0]] = $parts[1] ?? '';
    }

    return $entries;
}

/** Child process: prints the dump of one src/ tree to stdout. */
function dumpSource(string $srcDir, string $root): int
{
    if ($srcDir === '' || !is_dir($srcDir)) {
        fwrite(STDERR, "Usage: php bin/check-api-signature.php --dump <src-dir> <repository-root>\n");
        return 2;
    }

    $vendor = $root . '/vendor/autoload.php';
    if ($root !== '' && is_file($vendor)) {
        require_once $vendor;
    }
    // Prepended, so the tree in $srcDir wins over the composer mapping to the working tree.
    spl_autoload_register(
        static function (string $class) use ($srcDir): void {
            if (!str_starts_with($class, API_NAMESPACE_PREFIX)) {
                return;
            }
            $file = $srcDir . '/' . str_replace('\\', '/', substr($class, strlen(API_NAMESPACE_PREFIX))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        },
        true,
        true
    );

    $lines = [];
    foreach (classNamesIn($srcDir) as $name) {
        $lines += describeType($name);
    }

    ksort($lines);
    foreach ($lines as $key => $value) {
        echo $key, "\t", $value, "\n";
    }

    return 0;
}

/** @return list<string> */
function classNamesIn(string $srcDir): array
{
    $names = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($srcDir) + 1, -strlen('.php'));
        $names[] = API_NAMESPACE_PREFIX . str_replace('/', '\\', $relative);
    }
    sort($names);

    return $names;
}

/** @return array<string, string> */
function describeType(string $name): array
{
    if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
        return [];
    }

    $class = new ReflectionClass($name);
    $kind = match (true) {
        $class->isEnum() => 'enum',
        $class->isInterface() => 'interface',
        $class->isTrait() => 'trait',
        default => 'class',
    };

    $lines = [];
    $lines[sprintf('T|%s', $name)] = sprintf(
        'kind=%s;final=%d;abstract=%d;parent=%s',
        $kind,
        (int) $class->isFinal(),
        (int) $class->isAbstract(),
        $class->getParentClass() === false ? '' : $class->getParentClass()->getName()
    );

    foreach ($class->getInterfaceNames() as $interface) {
        $lines[sprintf('I|%s|%s', $name, $interface)] = 'implements';
    }

    $visible = ReflectionClassConstant::IS_PUBLIC | ReflectionClassConstant::IS_PROTECTED;
    foreach ($class->getReflectionConstants($visible) as $constant) {
        if ($constant->isEnumCase()) {
            $lines[sprintf('E|%s::%s', $name, $constant->getName())] = describeValue($constant->getValue());
            continue;
        }
        if (!isApiMember($constant, $class)) {
            continue;
        }
        $lines[sprintf('C|%s::%s', $name, $constant->getName())] = sprintf(
            'visibility=%s;final=%d;value=%s',
            visibilityOf($constant),
            (int) $constant->isFinal(),
            describeValue($constant->getValue())
        );
    }

    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
        if (!isApiMember($property, $class)) {
            continue;
        }
        $lines[sprintf('P|%s::$%s', $name, $property->getName())] = sprintf(
            'visibility=%s;static=%d;readonly=%d;type=%s;default=%s',
            visibilityOf($property),
            (int) $property->isStatic(),
            (int) $property->isReadOnly(),
            (string) ($property->getType() ?? 'none'),
            $property->hasDefaultValue() ? describeValue($property->getDefaultValue()) : 'none'
        );
    }

    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if (!isApiMember($method, $class)) {
            continue;
        }
        $lines[sprintf('M|%s::%s', $name, $method->getName())] = sprintf(
            'visibility=%s;static=%d;final=%d;abstract=%d;byref=%d;return=%s;params=%s',
            visibilityOf($method),
            (int) $method->isStatic(),
            (int) $method->isFinal(),
            (int) ($method->isAbstract() || $class->isInterface()),
            (int) $method->returnsReference(),
            (string) ($method->getReturnType() ?? 'none'),
            describeParameters($method)
        );
    }

    return $lines;
}

/**
 * Public members are API. Protected members are API only when a subclass can exist,
 * because a subclass depends on them. A protected member of a final class is not API.
 *
 * @param ReflectionClass<object> $class
 */
function isApiMember(
    ReflectionClassConstant|ReflectionProperty|ReflectionMethod $member,
    ReflectionClass $class
): bool {
    return $member->isPublic() || ($member->isProtected() && !$class->isFinal());
}

function visibilityOf(ReflectionClassConstant|ReflectionProperty|ReflectionMethod $member): string
{
    return $member->isPublic() ? 'public' : 'protected';
}

/** @return string JSON list of {signature, optional}; optional means a default value and not variadic */
function describeParameters(ReflectionFunctionAbstract $function): string
{
    $parameters = [];
    foreach ($function->getParameters() as $parameter) {
        $signature = sprintf(
            '%s%s$%s:%s',
            $parameter->isPassedByReference() ? '&' : '',
            $parameter->isVariadic() ? '...' : '',
            $parameter->getName(),
            (string) ($parameter->getType() ?? 'none')
        );
        $optional = false;
        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->isDefaultValueConstant()
                ? 'const ' . $parameter->getDefaultValueConstantName()
                : describeValue($parameter->getDefaultValue());
            $signature .= '=' . $default;
            $optional = !$parameter->isVariadic();
        }
        $parameters[] = ['signature' => $signature, 'optional' => $optional];
    }

    return (string) json_encode($parameters, JSON_UNESCAPED_SLASHES);
}

function describeValue(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value) || is_string($value)) {
        return (string) json_encode($value);
    }
    if (is_array($value)) {
        $items = [];
        foreach ($value as $key => $item) {
            $items[] = sprintf('%s => %s', (string) json_encode($key), describeValue($item));
        }

        return '[' . implode(', ', $items) . ']';
    }
    if ($value instanceof BackedEnum) {
        return sprintf('%s::%s=%s', $value::class, $value->name, describeValue($value->value));
    }
    if ($value instanceof UnitEnum) {
        return sprintf('%s::%s', $value::class, $value->name);
    }

    return is_object($value) ? 'new ' . $value::class : 'unknown';
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($dir);
}
