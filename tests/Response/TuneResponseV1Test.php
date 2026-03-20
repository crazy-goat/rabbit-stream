<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use PHPUnit\Framework\TestCase;

class TuneResponseV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $response = new TuneResponseV1(131072, 60);

        $bytes = $response->toStreamBuffer()->getContents();

        $expected = pack('n', 0x8014)   // key (TUNE_RESPONSE)
            . pack('n', 1)              // version
            . pack('N', 131072)         // frameMax
            . pack('N', 60);           // heartbeat

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithDefaults(): void
    {
        $response = new TuneResponseV1();

        $bytes = $response->toStreamBuffer()->getContents();

        $expected = pack('n', 0x8014)
            . pack('n', 1)
            . pack('N', 0)
            . pack('N', 0);

        $this->assertSame($expected, $bytes);
    }
}
