<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\VO\CommandVersion;
use PHPUnit\Framework\TestCase;

class CommandVersionTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $cv = new CommandVersion(0x0001, 1, 1);
        $this->assertSame(0x0001, $cv->getKey());
        $this->assertSame(1, $cv->getMinVersion());
        $this->assertSame(1, $cv->getMaxVersion());
    }

    public function testVersionRange(): void
    {
        $cv = new CommandVersion(0x0003, 1, 2);
        $this->assertSame(1, $cv->getMinVersion());
        $this->assertSame(2, $cv->getMaxVersion());
    }

    public function testZeroValues(): void
    {
        $cv = new CommandVersion(0, 0, 0);
        $this->assertSame(0, $cv->getKey());
        $this->assertSame(0, $cv->getMinVersion());
        $this->assertSame(0, $cv->getMaxVersion());
    }

    public function testMaxUint16Values(): void
    {
        $cv = new CommandVersion(0xFFFF, 0xFFFF, 0xFFFF);
        $this->assertSame(0xFFFF, $cv->getKey());
        $this->assertSame(0xFFFF, $cv->getMinVersion());
        $this->assertSame(0xFFFF, $cv->getMaxVersion());
    }

    public function testMinVersionGreaterThanMaxVersion(): void
    {
        // This should be allowed at value-object level (protocol validation is separate)
        $cv = new CommandVersion(0x0010, 3, 1);
        $this->assertSame(3, $cv->getMinVersion());
        $this->assertSame(1, $cv->getMaxVersion());
    }

    public function testRoundTripSerialization(): void
    {
        $cv = new CommandVersion(0x8001, 1, 3);
        $binary = $cv->toStreamBuffer()->getContents();
        $deserialized = CommandVersion::fromStreamBuffer(new ReadBuffer($binary));
        $this->assertNotNull($deserialized);
        $this->assertSame(0x8001, $deserialized->getKey());
        $this->assertSame(1, $deserialized->getMinVersion());
        $this->assertSame(3, $deserialized->getMaxVersion());
    }

    public function testBinarySerializationFormat(): void
    {
        $cv = new CommandVersion(0x0015, 2, 5);
        $binary = $cv->toStreamBuffer()->getContents();
        // Format: uint16 + uint16 + uint16 = 6 bytes
        $this->assertSame(6, strlen($binary));
        $expected = pack('n', 0x0015) . pack('n', 2) . pack('n', 5);
        $this->assertSame($expected, $binary);
    }

    public function testToArray(): void
    {
        $cv = new CommandVersion(0x0002, 1, 5);
        $this->assertSame(['key' => 0x0002, 'minVersion' => 1, 'maxVersion' => 5], $cv->toArray());
    }

    public function testFromArray(): void
    {
        $cv = CommandVersion::fromArray(['key' => 0x8011, 'minVersion' => 1, 'maxVersion' => 1]);
        $this->assertInstanceOf(CommandVersion::class, $cv);
        $this->assertSame(0x8011, $cv->getKey());
        $this->assertSame(1, $cv->getMinVersion());
        $this->assertSame(1, $cv->getMaxVersion());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $cv = new CommandVersion(0x001A, 1, 2);
        $array = $cv->toArray();
        $restored = CommandVersion::fromArray($array);
        $this->assertSame(0x001A, $restored->getKey());
        $this->assertSame(1, $restored->getMinVersion());
        $this->assertSame(2, $restored->getMaxVersion());
    }
}
