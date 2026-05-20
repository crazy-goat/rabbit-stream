<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\VO\Broker;
use PHPUnit\Framework\TestCase;

class BrokerTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $broker = new Broker(1, 'localhost', 5552);
        $this->assertSame(1, $broker->getReference());
        $this->assertSame('localhost', $broker->getHost());
        $this->assertSame(5552, $broker->getPort());
    }

    public function testZeroValues(): void
    {
        $broker = new Broker(0, '', 0);
        $this->assertSame(0, $broker->getReference());
        $this->assertSame('', $broker->getHost());
        $this->assertSame(0, $broker->getPort());
    }

    public function testLargeValues(): void
    {
        $broker = new Broker(65535, 'node-42.example.com', 4294967295);
        $this->assertSame(65535, $broker->getReference());
        $this->assertSame('node-42.example.com', $broker->getHost());
        $this->assertSame(4294967295, $broker->getPort());
    }

    public function testFromStreamBuffer(): void
    {
        $buffer = new ReadBuffer(
            pack('n', 5)           // reference (uint16)
            . pack('n', 6)         // host length (uint16)
            . 'host-1'              // host
            . pack('N', 5552)       // port (uint32)
        );
        $broker = Broker::fromStreamBuffer($buffer);
        $this->assertNotNull($broker);
        $this->assertSame(5, $broker->getReference());
        $this->assertSame('host-1', $broker->getHost());
        $this->assertSame(5552, $broker->getPort());
    }

    public function testToArray(): void
    {
        $broker = new Broker(3, 'rabbit-1', 5552);
        $this->assertSame(['reference' => 3, 'host' => 'rabbit-1', 'port' => 5552], $broker->toArray());
    }

    public function testFromArray(): void
    {
        $broker = Broker::fromArray(['reference' => 7, 'host' => 'node-3', 'port' => 5552]);
        $this->assertInstanceOf(Broker::class, $broker);
        $this->assertSame(7, $broker->getReference());
        $this->assertSame('node-3', $broker->getHost());
        $this->assertSame(5552, $broker->getPort());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $broker = new Broker(42, 'my-host.local', 61616);
        $array = $broker->toArray();
        $restored = Broker::fromArray($array);
        $this->assertSame(42, $restored->getReference());
        $this->assertSame('my-host.local', $restored->getHost());
        $this->assertSame(61616, $restored->getPort());
    }

    public function testFromStreamBufferNullHost(): void
    {
        $buffer = new ReadBuffer(
            pack('n', 1)            // reference (uint16)
            . pack('n', 0xFFFF)     // host: null (-1 as uint16)
            . pack('N', 5552)        // port (uint32)
        );
        $broker = Broker::fromStreamBuffer($buffer);
        $this->assertNotNull($broker);
        $this->assertSame(1, $broker->getReference());
        $this->assertSame('', $broker->getHost());
        $this->assertSame(5552, $broker->getPort());
    }
}
