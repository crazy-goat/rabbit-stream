<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use PHPUnit\Framework\TestCase;

class ExchangeCommandVersionsResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x801b)    // key (EXCHANGE_COMMAND_VERSIONS_RESPONSE)
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001)     // responseCode (OK)
            . pack('N', 2)          // 2 commands
            . pack('n', 0x0001)     // command key 1
            . pack('n', 1)          // minVersion
            . pack('n', 2)          // maxVersion
            . pack('n', 0x000d)     // command key 2
            . pack('n', 1)          // minVersion
            . pack('n', 1);         // maxVersion

        $response = ExchangeCommandVersionsResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(ExchangeCommandVersionsResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());

        $commands = $response->getCommands();
        $this->assertCount(2, $commands);
        $this->assertSame(0x0001, $commands[0]->getKey());
        $this->assertSame(1, $commands[0]->getMinVersion());
        $this->assertSame(2, $commands[0]->getMaxVersion());
        $this->assertSame(0x000d, $commands[1]->getKey());
        $this->assertSame(1, $commands[1]->getMinVersion());
        $this->assertSame(1, $commands[1]->getMaxVersion());
    }

    public function testDeserializesWithEmptyCommands(): void
    {
        $raw = pack('n', 0x801b)    // key
            . pack('n', 1)          // version
            . pack('N', 1)          // correlationId
            . pack('n', 0x0001)     // responseCode (OK)
            . pack('N', 0);         // 0 commands

        $response = ExchangeCommandVersionsResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(ExchangeCommandVersionsResponseV1::class, $response);
        $this->assertCount(0, $response->getCommands());
    }
}
