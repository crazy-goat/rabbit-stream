<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

/**
 * Guards #418 against OffsetSpec's public factories regressing back to the
 * state where only `none()` and `timestamp()` were documented and the rest
 * carried no prose at all.
 *
 * The accuracy of the semantics (what the broker resolves each type to) and of
 * the `@throws` tags cannot be asserted reflexively — they depend on broker
 * behaviour and on the constructor's validation, which a reflection test
 * cannot see. What reflection can see is checked here: every public static
 * factory has a non-empty prose description, every declared parameter has a
 * matching `@param`, every factory has a descriptive `@return` (the form
 * Rector's DEAD_CODE set keeps — FAQ-008), and every documented `@throws`
 * names a class that actually exists.
 */
class OffsetSpecDocblockTest extends TestCase
{
    private const EXCEPTION_NAMESPACE = 'CrazyGoat\\RabbitStream\\Exception\\';

    public function testEveryPublicFactoryIsDocumented(): void
    {
        $missingDocblock = [];
        $missingDescription = [];
        $missingParam = [];
        $missingReturn = [];

        foreach ($this->factories() as $method) {
            $label = OffsetSpec::class . '::' . $method->getName() . '()';
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

            if (!$this->hasReturnDescription($docblock)) {
                $missingReturn[] = $label;
            }
        }

        $this->assertSame(
            [],
            $missingDocblock,
            "Public OffsetSpec factories without a docblock:\n" . implode("\n", $missingDocblock)
        );
        $this->assertSame(
            [],
            $missingDescription,
            "Public OffsetSpec factories whose docblock has no prose description:\n"
                . implode("\n", $missingDescription)
        );
        $this->assertSame(
            [],
            $missingParam,
            "Public OffsetSpec factories missing an @param tag:\n" . implode("\n", $missingParam)
        );
        $this->assertSame(
            [],
            $missingReturn,
            "Public OffsetSpec factories whose return type has a missing or description-less @return tag:\n"
                . implode("\n", $missingReturn)
        );
    }

    /**
     * A documented `@throws Foo` that names a non-existent class or interface
     * is worse than no tag at all: it sends a caller looking for an exception
     * the library cannot raise. Names are resolved as written first, then
     * against the library exception namespace, so both `@throws
     * InvalidArgumentException` and `@throws \RuntimeException` are accepted.
     */
    public function testEveryDocumentedThrowsNamesARealClass(): void
    {
        $unknown = [];

        foreach ($this->factories() as $method) {
            $docblock = $method->getDocComment();
            if ($docblock === false) {
                continue;
            }

            $label = OffsetSpec::class . '::' . $method->getName() . '()';
            foreach ($this->unknownThrows($docblock, $label) as $name) {
                $unknown[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Documented @throws classes that do not exist:\n" . implode("\n", $unknown)
        );
    }

    /**
     * The constructor's `@throws` set is part of the contract a caller reads —
     * it validates the type/value pair eagerly — but the factory loop cannot
     * see it because the constructor is not static.
     */
    public function testConstructorDocumentsItsThrows(): void
    {
        $constructor = (new \ReflectionClass(OffsetSpec::class))->getConstructor();
        $this->assertNotNull($constructor);

        $docblock = $constructor->getDocComment();
        $this->assertNotFalse($docblock, 'OffsetSpec::__construct() must have a docblock.');
        $this->assertStringContainsString(
            '@throws InvalidArgumentException',
            $docblock,
            'The constructor must document the InvalidArgumentException it raises.'
        );
        $this->assertSame(
            [],
            $this->unknownThrows($docblock, 'OffsetSpec::__construct()'),
            "Documented @throws classes on the constructor that do not exist."
        );
    }

    /**
     * The `@throws` class names in a docblock that do not resolve to a real
     * class or interface.
     *
     * @return list<string>
     */
    private function unknownThrows(string $docblock, string $label): array
    {
        $unknown = [];

        preg_match_all('/@throws\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $docblock, $matches);
        foreach ($matches[1] as $name) {
            $name = ltrim($name, '\\');
            if (class_exists($name) || interface_exists($name)) {
                continue;
            }
            if (class_exists(self::EXCEPTION_NAMESPACE . $name)) {
                continue;
            }
            if (interface_exists(self::EXCEPTION_NAMESPACE . $name)) {
                continue;
            }

            $unknown[] = $label . ' -> ' . $name;
        }

        return $unknown;
    }

    /**
     * Regression for the generic-type case: a type such as
     * `array<string, string>` must be consumed as a whole (spaces inside the
     * angle brackets included) before deciding whether prose remains.
     */
    public function testReturnDescriptionHandlesGenericTypesWithSpaces(): void
    {
        $method = new \ReflectionMethod(self::class, 'hasReturnDescription');

        $bare = "/**\n * @return array<string, string>\n */";
        $this->assertFalse(
            $method->invoke($this, $bare),
            'A bare @return with a spaced generic type has no description.'
        );

        $inline = "/**\n * @return array<string, string> Map of filter name to value.\n */";
        $this->assertTrue(
            $method->invoke($this, $inline),
            'A @return with a spaced generic type and inline prose has a description.'
        );

        $nextLine = "/**\n * @return array<string, string>\n * Map of filter name to value.\n */";
        $this->assertTrue(
            $method->invoke($this, $nextLine),
            'A description on the line after the @return type counts.'
        );
    }

    /**
     * The public static factory methods on OffsetSpec.
     *
     * @return list<\ReflectionMethod>
     */
    private function factories(): array
    {
        return (new \ReflectionClass(OffsetSpec::class))->getMethods(\ReflectionMethod::IS_STATIC);
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
            if (preg_match('/^@return\s+(?:\S*<[^>]*>|\S*\{[^}]*\}|\S+)\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }
            if (trim($matches[1]) !== '') {
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
}
