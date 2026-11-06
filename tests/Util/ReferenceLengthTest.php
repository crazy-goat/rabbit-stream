<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Util\ReferenceLength;
use PHPUnit\Framework\TestCase;

class ReferenceLengthTest extends TestCase
{
    public function testMaxBytesIsTheBrokerLimit(): void
    {
        $this->assertSame(255, ReferenceLength::MAX_BYTES);
    }

    public function testAcceptsReferenceOfExactlyTheLimit(): void
    {
        ReferenceLength::assertValid(str_repeat('n', 255), 'consumer reference');

        $this->addToAssertionCount(1);
    }

    public function testRejectsReferenceOneByteOverTheLimitBeforeAnyFrame(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consumer reference is 256 bytes long');
        $this->expectExceptionMessage('at most 255 bytes');

        ReferenceLength::assertValid(str_repeat('n', 256), 'consumer reference');
    }

    public function testCountsBytesNotCharacters(): void
    {
        // 128 two-byte characters: 128 characters but 256 bytes.
        $this->expectException(InvalidArgumentException::class);

        ReferenceLength::assertValid(str_repeat('é', 128), 'producer name');
    }
}
