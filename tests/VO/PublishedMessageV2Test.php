<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\VO\PublishedMessageV2;
use PHPUnit\Framework\TestCase;

class PublishedMessageV2Test extends TestCase
{
    public function testToWireEncodesPublishingIdFilterValueAndBody(): void
    {
        $message = new PublishedMessageV2(1, 'filter', 'body');

        self::assertSame(
            "\x00\x00\x00\x00\x00\x00\x00\x01\x00\x06filter\x00\x00\x00\x04body",
            $message->toWire()
        );
    }

    public function testToWireEncodesEmptyFilterValueAndBody(): void
    {
        $message = new PublishedMessageV2(0, '', '');

        self::assertSame("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", $message->toWire());
    }

    public function testToWireRejectsNegativePublishingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PublishedMessageV2(-1, '', ''))->toWire();
    }

    public function testToWireRejectsTooLongFilterValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PublishedMessageV2(0, str_repeat('a', 32768), ''))->toWire();
    }

    public function testToWireRejectsInvalidUtf8FilterValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PublishedMessageV2(0, "\xFF", ''))->toWire();
    }
}
