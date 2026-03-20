<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use PHPUnit\Framework\TestCase;

class CreditRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new CreditRequestV1(5, 100);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0009)   // key
            . pack('n', 1)              // version
            . pack('C', 5)              // subscriptionId
            . pack('n', 100);           // credit

        $this->assertSame($expected, $bytes);
    }
}
