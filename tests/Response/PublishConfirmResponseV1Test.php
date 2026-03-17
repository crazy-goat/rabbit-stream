<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;
use PHPUnit\Framework\TestCase;

class PublishConfirmResponseV1Test extends TestCase
{
    public function testDeserializesWithSinglePublishingId(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 1)
            . pack('J', 42);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);
        $this->assertSame(5, $response->getPublisherId());
        $this->assertSame([42], $response->getPublishingIds());
    }

    public function testDeserializesWithMultiplePublishingIds(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 1)
            . pack('N', 3)
            . pack('J', 1)
            . pack('J', 2)
            . pack('J', 3);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertSame(1, $response->getPublisherId());
        $this->assertSame([1, 2, 3], $response->getPublishingIds());
    }

    public function testDeserializesWithNoPublishingIds(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 2)
            . pack('N', 0);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertSame(2, $response->getPublisherId());
        $this->assertSame([], $response->getPublishingIds());
    }
}
