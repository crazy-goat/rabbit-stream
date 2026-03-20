<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use PHPUnit\Framework\TestCase;

class DeleteSuperStreamResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x801e)    // key (DELETE_SUPER_STREAM_RESPONSE)
            . pack('n', 1)          // version
            . pack('N', 42)         // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = DeleteSuperStreamResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeleteSuperStreamResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x801e)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // Super stream does not exist

        $this->expectException(\Exception::class);
        DeleteSuperStreamResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
