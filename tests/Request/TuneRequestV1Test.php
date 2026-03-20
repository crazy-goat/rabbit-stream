<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use PHPUnit\Framework\TestCase;

class TuneRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new TuneRequestV1(131072, 60);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0014)       // key
            . pack('n', 1)                  // version
            . pack('N', 131072)             // frameMax
            . pack('N', 60);               // heartbeat

        $this->assertSame($expected, $bytes);
    }

    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x0014)
            . pack('n', 1)
            . pack('N', 131072)
            . pack('N', 60);

        $request = TuneRequestV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(TuneRequestV1::class, $request);
        $this->assertSame(131072, $request->getFrameMax());
        $this->assertSame(60, $request->getHeartbeat());
    }

    public function testRoundTrip(): void
    {
        $original = new TuneRequestV1(65536, 30);
        $bytes = $original->toStreamBuffer()->getContents();
        $decoded = TuneRequestV1::fromStreamBuffer(new ReadBuffer($bytes));
        $this->assertInstanceOf(TuneRequestV1::class, $decoded);

        $this->assertSame($original->getFrameMax(), $decoded->getFrameMax());
        $this->assertSame($original->getHeartbeat(), $decoded->getHeartbeat());
    }
}
