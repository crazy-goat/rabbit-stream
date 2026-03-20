<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use PHPUnit\Framework\TestCase;

class CreateResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x800d)    // key
            . pack('n', 1)          // version
            . pack('N', 42)         // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = CreateResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CreateResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x800d)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0005); // Stream already exists

        $this->expectException(\Exception::class);
        CreateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
