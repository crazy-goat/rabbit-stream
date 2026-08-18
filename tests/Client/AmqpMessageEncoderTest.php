<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;
use CrazyGoat\RabbitStream\Client\ChunkEntry;
use PHPUnit\Framework\TestCase;

class AmqpMessageEncoderTest extends TestCase
{
    public function testEncodeDataSectionProducesKnownWireBytes(): void
    {
        $this->assertSame(
            "\x00\x53\x75\xb0" . pack('N', 5) . 'hello',
            AmqpMessageEncoder::encodeDataSection('hello')
        );
    }

    public function testEncodeIsAliasOfEncodeDataSection(): void
    {
        $body = 'alias body';
        $this->assertSame(
            AmqpMessageEncoder::encodeDataSection($body),
            AmqpMessageEncoder::encode($body)
        );
    }

    public function testEmptyBodyWrapsWithZeroLength(): void
    {
        $encoded = AmqpMessageEncoder::encodeDataSection('');

        $this->assertSame("\x00\x53\x75\xb0" . pack('N', 0), $encoded);

        $sections = AmqpDecoder::decodeMessage($encoded);
        $this->assertSame('', $sections['body']);
    }

    public function testEncodedBodyDecodesBackToOriginalViaAmqpMessageDecoder(): void
    {
        $body = "Héllo Wörld 🐰\x00with\x00nulls";
        $message = AmqpMessageDecoder::decode(
            new ChunkEntry(0, AmqpMessageEncoder::encodeDataSection($body), 0)
        );

        $this->assertSame($body, $message->getBody());
    }

    public function testBinaryBodyDecodesBackByteForByte(): void
    {
        $body = random_bytes(512);
        $encoded = AmqpMessageEncoder::encodeDataSection($body);

        $sections = AmqpDecoder::decodeMessage($encoded);
        $this->assertSame($body, $sections['body']);
    }
}
