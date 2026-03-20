<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\CreditResponseV1;
use PHPUnit\Framework\TestCase;

class CreditResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8009)    // key
            . pack('n', 1)          // version
            . pack('n', 0x0001)     // responseCode OK
            . pack('C', 5);          // subscriptionId

        $response = CreditResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CreditResponseV1::class, $response);
        $this->assertSame(0x0001, $response->getResponseCode());
        $this->assertSame(5, $response->getSubscriptionId());
    }

    public function testDeserializesErrorResponse(): void
    {
        $raw = pack('n', 0x8009)    // key
            . pack('n', 1)          // version
            . pack('n', 0x0010)     // responseCode: Subscription does not exist
            . pack('C', 99);         // subscriptionId

        $response = CreditResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CreditResponseV1::class, $response);
        $this->assertSame(0x0010, $response->getResponseCode());
        $this->assertSame(99, $response->getSubscriptionId());
    }
}
