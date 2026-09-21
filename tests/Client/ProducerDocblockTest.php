<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Producer;
use PHPUnit\Framework\TestCase;

/**
 * Guards #415 against Producer's public API regressing back to the state where
 * it was undocumented (constructor, send()'s payload/body contract, the
 * back-pressure `maxPendingConfirms` semantics, and the counter-intuitive
 * named-producer getLastPublishingId() behaviour).
 *
 * The accuracy of the `@throws` tags and the prose themselves cannot be
 * asserted reflexively — they depend on transitive throw paths through the
 * DeclarePublisher/QueryPublisherSequence exchanges, the publish path and the
 * back-pressure read loop, which a reflection test cannot see. What reflection
 * can see is checked here: every public method has a non-empty prose
 * description, every declared parameter has a matching `@param`, every method
 * with a non-void return type has a descriptive `@return` (one Rector's
 * DEAD_CODE set will not strip — FAQ-008), every documented `@throws` names
 * a class that actually exists, and every throwing public method declares the
 * exact `@throws` set its callers must handle (EXPECTED_THROWS).
 */
class ProducerDocblockTest extends TestCase
{
    private const EXCEPTION_NAMESPACE = 'CrazyGoat\\RabbitStream\\Exception\\';

    /**
     * The `@throws` classes each public Producer method must document, keyed
     * by method name. This is the mechanical half of the "accuracy cannot be
     * asserted reflexively" caveat: the sets were derived by tracing the real
     * throw paths, and pinning them here makes removing a tag (e.g. the
     * `ProtocolException` that `waitForConfirms()` can raise through
     * `readLoop()` → `dispatchServerPush()` → `validateKeyVersion()`) fail the
     * build instead of silently weakening the documentation.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_THROWS = [
        '__construct' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
            'UnexpectedResponseException',
        ],
        'close' => [
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
            'TimeoutException',
        ],
        'getLastPublishingId' => [],
        'getLostConfirmCount' => [],
        'getPendingConfirms' => [],
        'getRedeclareCount' => [],
        'isClosed' => [],
        'isStale' => [],
        'querySequence' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
            'UnexpectedResponseException',
        ],
        'send' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'sendBatch' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'sendWithFilter' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'waitForConfirms' => [
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
            'TimeoutException',
        ],
    ];

    public function testEveryPublicMethodIsDocumented(): void
    {
        $missingDocblock = [];
        $missingDescription = [];
        $missingParam = [];
        $missingReturn = [];

        $methods = (new \ReflectionClass(Producer::class))
            ->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $label = Producer::class . '::' . $method->getName() . '()';
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
            "Public methods on Producer without a docblock:\n" . implode("\n", $missingDocblock)
        );
        $this->assertSame(
            [],
            $missingDescription,
            "Public methods on Producer whose docblock has no prose description:\n"
                . implode("\n", $missingDescription)
        );
        $this->assertSame(
            [],
            $missingParam,
            "Public methods on Producer missing an @param tag:\n" . implode("\n", $missingParam)
        );
        $this->assertSame(
            [],
            $missingReturn,
            "Public methods on Producer whose non-void return type has a missing or description-less @return tag:\n"
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

        foreach ((new \ReflectionClass(Producer::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
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

                $unknown[] = Producer::class . '::' . $method->getName() . '() -> ' . $name;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Documented @throws classes that do not exist:\n" . implode("\n", $unknown)
        );
    }

    /**
     * Pin the exact `@throws` set of every public method. This closes the
     * "accuracy cannot be asserted reflexively" gap: a documented-but-wrong
     * class is caught by testEveryDocumentedThrowsNamesARealClass, but a
     * *missing* tag was previously invisible. Removing any expected tag (or
     * adding an unexpected one) fails here, and a new public method without an
     * entry in EXPECTED_THROWS fails too.
     */
    public function testEveryThrowingPublicMethodDeclaresItsExpectedThrows(): void
    {
        $actual = [];

        foreach ((new \ReflectionClass(Producer::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $docblock = $method->getDocComment();
            $throws = $docblock === false ? [] : $this->documentedThrows($docblock);
            sort($throws);
            $actual[$method->getName()] = $throws;
        }
        ksort($actual);

        $expected = self::EXPECTED_THROWS;
        foreach ($expected as &$throws) {
            sort($throws);
        }
        unset($throws);
        ksort($expected);

        $mismatches = [];
        foreach (array_unique(array_merge(array_keys($actual), array_keys($expected))) as $name) {
            if (($actual[$name] ?? null) !== ($expected[$name] ?? null)) {
                $mismatches[$name] = [
                    'expected' => $expected[$name] ?? null,
                    'actual' => $actual[$name] ?? null,
                ];
            }
        }

        $this->assertSame(
            $expected,
            $actual,
            "Producer public methods whose documented @throws set does not match the expected set "
                . "(update EXPECTED_THROWS if the real throw paths changed):\n"
                . var_export($mismatches, true)
        );
    }

    /**
     * The `@throws` class names in a docblock, with a leading backslash
     * stripped, in declaration order.
     *
     * @return list<string>
     */
    private function documentedThrows(string $docblock): array
    {
        preg_match_all('/@throws\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $docblock, $matches);

        return array_map(
            static fn (string $name): string => ltrim($name, '\\'),
            $matches[1]
        );
    }

    /**
     * Regression for the generic-type case: a type such as
     * `array<string, string>` must be consumed as a whole (spaces inside the
     * angle brackets included) before deciding whether prose remains. The old
     * regex split on the inner space and treated `string>` as a description.
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
