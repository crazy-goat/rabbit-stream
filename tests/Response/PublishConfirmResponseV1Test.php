<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;
use PHPUnit\Framework\TestCase;

class PublishConfirmResponseV1Test extends TestCase
{
    public function testDeserializesWithSinglePublishingId(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 1)
            . pack('J', 42);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);
        $this->assertSame(5, $response->getPublisherId());
        $this->assertSame([42], $response->getPublishingIds());
    }

    public function testDeserializesWithMultiplePublishingIds(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 1)
            . pack('N', 3)
            . pack('J', 1)
            . pack('J', 2)
            . pack('J', 3);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);

        $this->assertSame(1, $response->getPublisherId());
        $this->assertSame([1, 2, 3], $response->getPublishingIds());
    }

    public function testDeserializesWithNoPublishingIds(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 2)
            . pack('N', 0);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);

        $this->assertSame(2, $response->getPublisherId());
        $this->assertSame([], $response->getPublishingIds());
    }

    public function testDeserializesWithMaxRepresentablePublishingId(): void
    {
        // PHP_INT_MAX (0x7FFFFFFFFFFFFFFF) is the largest publishing id a PHP int
        // can hold, so it must still be accepted — the guard rejects 2^63 and up.
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 1)
            . pack('N', 1)
            . pack('J', PHP_INT_MAX);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);

        $this->assertSame([PHP_INT_MAX], $response->getPublishingIds());
    }

    public function testDeserializesWithPublishingIdAbovePhpIntMaxThrows(): void
    {
        // Regression guard for #536: the ids used to be read with one bulk
        // unpack('J*', ...) call, which bypassed the ReadBuffer::getUint64()
        // guard added in #393. 0xFFFFFFFFFFFFFFFF unpacked to -1 and flowed to
        // the publisher's confirm callback, where it can never match a tracked
        // publishing id.
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 1)
            . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";

        try {
            PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('0xffffffffffffffff', $e->getMessage());
            $this->assertStringContainsString('exceeds PHP_INT_MAX', $e->getMessage());
        }
    }

    public function testDeserializesWithPublishingIdAtTwoToThe63Throws(): void
    {
        // The exact threshold: 0x8000000000000000 is PHP_INT_MAX + 1 and the
        // first value the signed unpack('J*') would wrap to PHP_INT_MIN.
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 1)
            . "\x80\x00\x00\x00\x00\x00\x00\x00";

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('0x8000000000000000');
        PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testDeserializesWithOutOfRangeIdAfterValidOnesThrows(): void
    {
        // The whole array must be scanned: a bad id is rejected even when the
        // ids before it are ordinary values a naive first-element check misses.
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 3)
            . pack('J', 1)
            . pack('J', 2)
            . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('0xffffffffffffffff');
        PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testDeserializesWithCountSmallerThanFrameIgnoresTrailingIds(): void
    {
        // The header's count is authoritative: a frame carrying more ids than it
        // declares must yield exactly the declared number, not the extra ones.
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 2)
            . pack('J', 1)
            . pack('J', 2)
            . pack('J', 3)
            . pack('J', 4);

        $response = PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);

        $this->assertSame([1, 2], $response->getPublishingIds());
    }

    public function testDeserializesWithCountLargerThanFrameThrows(): void
    {
        // A truncated frame must fail on the bounds check, not silently hand
        // back fewer ids than the header promised.
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 5)
            . pack('N', 4)
            . pack('J', 1)
            . pack('J', 2);

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Buffer underflow');
        PublishConfirmResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
