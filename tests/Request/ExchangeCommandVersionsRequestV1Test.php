<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\VO\CommandVersion;
use PHPUnit\Framework\TestCase;

class ExchangeCommandVersionsRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new ExchangeCommandVersionsRequestV1([
            new CommandVersion(0x0001, 1, 2),
            new CommandVersion(0x000d, 1, 1),
        ]);
        $request->withCorrelationId(7);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001b)   // key (EXCHANGE_COMMAND_VERSIONS)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('N', 2)              // 2 commands
            . pack('n', 0x0001)         // command key 1
            . pack('n', 1)              // minVersion
            . pack('n', 2)              // maxVersion
            . pack('n', 0x000d)         // command key 2
            . pack('n', 1)              // minVersion
            . pack('n', 1);             // maxVersion

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithEmptyCommands(): void
    {
        $request = new ExchangeCommandVersionsRequestV1([]);
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001b)   // key
            . pack('n', 1)              // version
            . pack('N', 1)              // correlationId
            . pack('N', 0);             // 0 commands

        $this->assertSame($expected, $bytes);
    }
}
