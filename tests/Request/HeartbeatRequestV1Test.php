<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;
use PHPUnit\Framework\TestCase;

class HeartbeatRequestV1Test extends TestCase
{
    public function testSerializes(): void
    {
        $request = new HeartbeatRequestV1();
        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0017) . pack('n', 1);

        $this->assertSame($expected, $bytes);
    }

    public function testDeserializes(): void
    {
        $raw = pack('n', 0x0017) . pack('n', 1);
        $result = HeartbeatRequestV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(HeartbeatRequestV1::class, $result);
    }
}
