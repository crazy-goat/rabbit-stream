<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateQueryV1;
use PHPUnit\Framework\TestCase;

class ConsumerUpdateQueryV1Test extends TestCase
{
    public function testDeserializesActive(): void
    {
        $raw = pack('n', 0x001a)
            . pack('n', 1)
            . pack('N', 42)
            . pack('C', 3)
            . pack('C', 1);

        $response = ConsumerUpdateQueryV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(ConsumerUpdateQueryV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
        $this->assertSame(3, $response->getSubscriptionId());
        $this->assertTrue($response->isActive());
    }

    public function testDeserializesInactive(): void
    {
        $raw = pack('n', 0x001a)
            . pack('n', 1)
            . pack('N', 1)
            . pack('C', 1)
            . pack('C', 0);

        $response = ConsumerUpdateQueryV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertFalse($response->isActive());
    }
}
