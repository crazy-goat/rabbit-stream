<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use PHPUnit\Framework\TestCase;

class SubscribeResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8007)    // key
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = SubscribeResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8007)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0003); // Subscription ID does not exist

        $this->expectException(\Exception::class);
        SubscribeResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testThrowsOnStreamNotExist(): void
    {
        $raw = pack('n', 0x8007)
            . pack('n', 1)
            . pack('N', 2)
            . pack('n', 0x0002); // Stream does not exist

        $this->expectException(\Exception::class);
        SubscribeResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testThrowsOnSubscriptionIdAlreadyExists(): void
    {
        $raw = pack('n', 0x8007)
            . pack('n', 1)
            . pack('N', 3)
            . pack('n', 0x0003); // Subscription ID already exists

        $this->expectException(\Exception::class);
        SubscribeResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
