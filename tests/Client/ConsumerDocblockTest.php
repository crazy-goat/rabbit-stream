<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Consumer;
use PHPUnit\Framework\TestCase;

/**
 * Guards #416 against Consumer's public API regressing back to the state where
 * it was undocumented (constructor, read()/readOne() contract, the
 * `maxBufferSize` back-pressure semantics).
 *
 * The accuracy of the `@throws` tags and the prose themselves cannot be
 * asserted reflexively — they depend on transitive throw paths through the
 * Subscribe request, delivered-chunk parsing and re-subscribe, which a
 * reflection test cannot see. What reflection can see is checked here: every
 * public method has a non-empty prose description, every declared parameter has
 * a matching `@param`, every method with a non-void return type has a
 * descriptive `@return` (one Rector's DEAD_CODE set will not strip — FAQ-008),
 * and every documented `@throws` names a class that actually exists.
 */
class ConsumerDocblockTest extends TestCase
{
    private const EXCEPTION_NAMESPACE = 'CrazyGoat\\RabbitStream\\Exception\\';

    public function testEveryPublicMethodIsDocumented(): void
    {
        $missingDocblock = [];
        $missingDescription = [];
        $missingParam = [];
        $missingReturn = [];

        $methods = (new \ReflectionClass(Consumer::class))
            ->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $label = Consumer::class . '::' . $method->getName() . '()';
            $docblock = $method->getDocComment();

            if ($docblock === false || trim($docblock) === '') {
                $missingDocblock[] = $label;
                continue;
            }

            if ($this->description($docblock) === '') {
                $missingDescription[] = $label;
            }

            foreach ($method->getParameters() as $parameter) {
                $tag = '@param\b[^\n]*\$' . preg_quote($parameter->getName(), '/') . '\b';
                if (preg_match('/' . $tag . '/', $docblock) !== 1) {
                    $missingParam[] = $label . ' (missing @param $' . $parameter->getName() . ')';
                }
            }

            if ($this->needsReturnTag($method) && !$this->hasReturnDescription($docblock)) {
                $missingReturn[] = $label;
            }
        }

        $this->assertSame(
            [],
            $missingDocblock,
            "Public methods on Consumer without a docblock:\n" . implode("\n", $missingDocblock)
        );
        $this->assertSame(
            [],
            $missingDescription,
            "Public methods on Consumer whose docblock has no prose description:\n"
                . implode("\n", $missingDescription)
        );
        $this->assertSame(
            [],
            $missingParam,
            "Public methods on Consumer missing an @param tag:\n" . implode("\n", $missingParam)
        );
        $this->assertSame(
            [],
            $missingReturn,
            "Public methods on Consumer whose non-void return type has a missing or description-less @return tag:\n"
                . implode("\n", $missingReturn)
        );
    }

    /**
     * A documented `@throws Foo` that names a non-existent class or interface
     * is worse than no tag at all: it sends a caller looking for an exception
     * the library cannot raise. Names are resolved as written first, then
     * against the library exception namespace, so both `@throws
     * ProtocolException` and `@throws \RuntimeException` are accepted.
     */
    public function testEveryDocumentedThrowsNamesARealClass(): void
    {
        $unknown = [];

        foreach ((new \ReflectionClass(Consumer::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $docblock = $method->getDocComment();
            if ($docblock === false) {
                continue;
            }

            preg_match_all('/@throws\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $docblock, $matches);
            foreach ($matches[1] as $name) {
                $name = ltrim($name, '\\');
                if (class_exists($name)) {
                    continue;
                }
                if (interface_exists($name)) {
                    continue;
                }
                if (class_exists(self::EXCEPTION_NAMESPACE . $name)) {
                    continue;
                }
                if (interface_exists(self::EXCEPTION_NAMESPACE . $name)) {
                    continue;
                }

                $unknown[] = Consumer::class . '::' . $method->getName() . '() -> ' . $name;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Documented @throws classes that do not exist:\n" . implode("\n", $unknown)
        );
    }

    /**
     * The prose description: the docblock text before the first `@tag`.
     */
    private function description(string $docblock): string
    {
        $body = preg_replace('~^/\*\*|\*/$~', '', trim($docblock)) ?? '';
        $lines = explode("\n", $body);
        $prose = [];

        foreach ($lines as $line) {
            $line = ltrim(trim($line), '*');
            $line = trim($line);
            if (str_starts_with($line, '@')) {
                break;
            }
            $prose[] = $line;
        }

        return trim(implode(' ', $prose));
    }

    /**
     * Whether the docblock has an `@return` tag carrying a description after
     * its type. A bare `@return <type>` whose type merely repeats the native
     * return type is removed by Rector's DEAD_CODE set (FAQ-008), so tag
     * presence alone is not enough — the gate must require prose too. A
     * description may sit on the tag line or on the continuation line(s).
     */
    private function hasReturnDescription(string $docblock): bool
    {
        $body = preg_replace('~^/\*\*|\*/$~', '', trim($docblock)) ?? '';
        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            $line = trim(ltrim(trim($line), '*'));
            if (preg_match('/^@return\s+(\S+)\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }
            if (trim($matches[2]) !== '') {
                return true;
            }
            for ($next = $index + 1, $count = count($lines); $next < $count; $next++) {
                $continuation = trim(ltrim(trim($lines[$next]), '*'));
                if ($continuation === '' || str_starts_with($continuation, '@')) {
                    break;
                }
                return true;
            }
        }

        return false;
    }

    private function needsReturnTag(\ReflectionMethod $method): bool
    {
        $name = strtolower($method->getName());
        if ($name === '__construct' || $name === '__destruct') {
            return false;
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType) {
            // No declared return type, or a union/intersection type: there is no
            // single `void` to exempt, so a tag is expected whenever a type was
            // declared at all.
            return $returnType !== null;
        }

        return $returnType->getName() !== 'void';
    }
}
