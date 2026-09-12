<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Guards #414 against the docs regressing back to the state where
 * `Connection`'s public API was entirely undocumented.
 *
 * The accuracy of the `@throws` tags cannot be asserted reflexively — it
 * depends on the transitive throw paths through `readMessage()`, response
 * deserialization and the `Producer`/`Consumer` constructors, which a
 * reflection test cannot see. What reflection can see is checked here: every
 * public method has a non-empty docblock, every declared parameter has a
 * matching `@param`, and every method with a non-void return type has an
 * `@return`.
 */
class ConnectionDocblockTest extends TestCase
{
    public function testEveryPublicMethodIsDocumented(): void
    {
        $missingDocblock = [];
        $missingParam = [];
        $missingReturn = [];

        $methods = (new \ReflectionClass(Connection::class))
            ->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $label = Connection::class . '::' . $method->getName() . '()';
            $docblock = $method->getDocComment();

            if ($docblock === false || trim($docblock) === '') {
                $missingDocblock[] = $label;
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $tag = '@param\b[^\n]*\$' . preg_quote($parameter->getName(), '/') . '\b';
                if (preg_match('/' . $tag . '/', $docblock) !== 1) {
                    $missingParam[] = $label . ' (missing @param $' . $parameter->getName() . ')';
                }
            }

            if ($this->needsReturnTag($method) && preg_match('/@return\b/', $docblock) !== 1) {
                $missingReturn[] = $label;
            }
        }

        $this->assertSame(
            [],
            $missingDocblock,
            "Public methods on Connection without a docblock:\n" . implode("\n", $missingDocblock)
        );
        $this->assertSame(
            [],
            $missingParam,
            "Public methods on Connection missing an @param tag:\n" . implode("\n", $missingParam)
        );
        $this->assertSame(
            [],
            $missingReturn,
            "Public methods on Connection whose non-void return type has no @return tag:\n"
                . implode("\n", $missingReturn)
        );
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
