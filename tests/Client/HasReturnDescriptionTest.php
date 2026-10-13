<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use PHPUnit\Framework\TestCase;

class HasReturnDescriptionTest extends TestCase
{
    use HasReturnDescription;

    public function testReturnDescriptionHandlesGenericTypesWithSpaces(): void
    {
        $bare = "/**\n * @return array<string, string>\n */";
        $this->assertFalse(
            $this->hasReturnDescription($bare),
            'A bare @return with a spaced generic type has no description.'
        );

        $inline = "/**\n * @return array<string, string> Map of filter name to value.\n */";
        $this->assertTrue(
            $this->hasReturnDescription($inline),
            'A @return with a spaced generic type and inline prose has a description.'
        );

        $nextLine = "/**\n * @return array<string, string>\n * Map of filter name to value.\n */";
        $this->assertTrue(
            $this->hasReturnDescription($nextLine),
            'A description on the line after the @return type counts.'
        );
    }
}
