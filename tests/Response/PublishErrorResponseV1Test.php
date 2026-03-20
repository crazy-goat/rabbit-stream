<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;
use PHPUnit\Framework\TestCase;

class PublishErrorResponseV1Test extends TestCase
{
    public function testDeserializesWithSingleError(): void
    {
        $raw = pack('n', 0x0004)
            . pack('n', 1)
            . pack('C', 3)
            . pack('N', 1)
            . pack('J', 99)
            . pack('n', 0x0002);

        $response = PublishErrorResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PublishErrorResponseV1::class, $response);
        $this->assertSame(3, $response->getPublisherId());
        $errors = $response->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame(99, $errors[0]->getPublishingId());
        $this->assertSame(0x0002, $errors[0]->getCode());
    }

    public function testDeserializesWithMultipleErrors(): void
    {
        $raw = pack('n', 0x0004)
            . pack('n', 1)
            . pack('C', 1)
            . pack('N', 2)
            . pack('J', 10) . pack('n', 0x0001)
            . pack('J', 20) . pack('n', 0x0002);

        $response = PublishErrorResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertSame(1, $response->getPublisherId());
        $errors = $response->getErrors();
        $this->assertCount(2, $errors);
        $this->assertSame(10, $errors[0]->getPublishingId());
        $this->assertSame(20, $errors[1]->getPublishingId());
    }
}
