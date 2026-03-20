<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use PHPUnit\Framework\TestCase;

class CreateSuperStreamResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x801d)           // key (CREATE_SUPER_STREAM_RESPONSE)
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001);            // response code (OK)

        $response = CreateSuperStreamResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CreateSuperStreamResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }

    public function testThrowsOnNonOkResponseCode(): void
    {
        $raw = pack('n', 0x801d)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0005);            // response code (Stream already exists)

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            'Unexpected response code: 0x0005 (STREAM_ALREADY_EXISTS: Stream already exists)'
        );

        CreateSuperStreamResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
