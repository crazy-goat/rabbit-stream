<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
use CrazyGoat\RabbitStream\Exception\UnsupportedPlatformException;
use CrazyGoat\RabbitStream\Platform;
use PHPUnit\Framework\TestCase;

class PlatformTest extends TestCase
{
    public function testSixtyFourBitIntegersAreAcceptedSilently(): void
    {
        // The guard is on the decode hot path (every ReadBuffer, every top-level
        // AMQP value), so on a supported build it must be a no-op.
        $this->assertSame(8, PHP_INT_SIZE, 'The test suite itself requires a 64-bit build');
        Platform::assertSixtyFourBitIntegers();
        $this->assertInstanceOf(ReadBuffer::class, new ReadBuffer("\x00\x00\x00\x01"));
    }

    public function testUnsupportedPlatformExceptionIsPartOfTheLibraryHierarchy(): void
    {
        // #458 refuses to run on a 32-bit build, which cannot be reproduced from a
        // 64-bit test run. What can be asserted is that the refusal is catchable
        // the same way as every other error this library raises (#242) — a caller
        // wrapping its consume loop in catch (RabbitStreamExceptionInterface) will
        // see it rather than an uncatchable surprise.
        $exception = new UnsupportedPlatformException('32-bit');

        $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
