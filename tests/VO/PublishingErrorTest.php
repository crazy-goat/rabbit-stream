<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\VO\PublishingError;
use PHPUnit\Framework\TestCase;

class PublishingErrorTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $err = new PublishingError(42, 0x0001);
        $this->assertSame(42, $err->getPublishingId());
        $this->assertSame(0x0001, $err->getCode());
    }

    public function testZeroPublishingId(): void
    {
        $err = new PublishingError(0, 0x0002);
        $this->assertSame(0, $err->getPublishingId());
        $this->assertSame(0x0002, $err->getCode());
    }

    public function testLargePublishingId(): void
    {
        $err = new PublishingError(9223372036854775807, 0xFFFF);
        $this->assertSame(9223372036854775807, $err->getPublishingId());
        $this->assertSame(0xFFFF, $err->getCode());
    }

    public function testFromStreamBuffer(): void
    {
        $buffer = new ReadBuffer(
            pack('J', 100)     // publishingId (uint64)
            . pack('n', 7)     // code (uint16)
        );
        $err = PublishingError::fromStreamBuffer($buffer);
        $this->assertNotNull($err);
        $this->assertSame(100, $err->getPublishingId());
        $this->assertSame(7, $err->getCode());
    }

    public function testToArray(): void
    {
        $err = new PublishingError(42, 0x0001);
        $this->assertSame(['publishingId' => 42, 'code' => 0x0001], $err->toArray());
    }

    public function testFromArray(): void
    {
        $err = PublishingError::fromArray(['publishingId' => 99, 'code' => 0x0003]);
        $this->assertInstanceOf(PublishingError::class, $err);
        $this->assertSame(99, $err->getPublishingId());
        $this->assertSame(0x0003, $err->getCode());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $err = new PublishingError(777, 0x0005);
        $array = $err->toArray();
        $restored = PublishingError::fromArray($array);
        $this->assertSame(777, $restored->getPublishingId());
        $this->assertSame(0x0005, $restored->getCode());
    }
}
