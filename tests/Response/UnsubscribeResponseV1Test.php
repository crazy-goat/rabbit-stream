<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;
use PHPUnit\Framework\TestCase;

class UnsubscribeResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x800c)    // key
            . pack('n', 1)          // version
            . pack('N', 42)         // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = UnsubscribeResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(UnsubscribeResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x800c)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0010); // Subscription not found

        $this->expectException(\Exception::class);
        UnsubscribeResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
