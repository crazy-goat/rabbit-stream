<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use PHPUnit\Framework\TestCase;

class UnsubscribeRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new UnsubscribeRequestV1(5);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000c)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('C', 5);             // subscriptionId

        $this->assertSame($expected, $bytes);
    }
}
