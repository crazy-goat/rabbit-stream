<?php

namespace CrazyGoat\StreamyCarrot\Tests\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Response\PeerPropertiesResponseV1;
use PHPUnit\Framework\TestCase;

class PeerPropertiesResponseV1Test extends TestCase
{
    public function testDeserializesWithProperties(): void
    {
        $raw = pack('n', 0x8011)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0001)
            . pack('N', 1)                          // 1 property
            . pack('n', 7) . 'product'              // key
            . pack('n', 8) . 'RabbitMQ';           // value

        $response = PeerPropertiesResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $response);
        $this->assertCount(1, $response->getPeerProperty());
    }

    public function testDeserializesWithEmptyProperties(): void
    {
        $raw = pack('n', 0x8011)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0001)
            . pack('N', 0);

        $response = PeerPropertiesResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $response);
        $this->assertCount(0, $response->getPeerProperty());
    }
}
