<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

/**
 * Encodes message payloads as AMQP 1.0 sections for publishing.
 *
 * RabbitStream consumers decode every delivered entry as an AMQP 1.0
 * message (see AmqpDecoder::decodeMessage()). Producers therefore wrap
 * each payload in an AMQP 1.0 Data section (descriptor 0x75) so that a
 * plain string published via Producer::send() round-trips to
 * Message::getBody() as the same plain string.
 */
class AmqpMessageEncoder
{
    /**
     * Wrap a raw payload in an AMQP 1.0 Data section.
     *
     * Wire format: 0x00 (described-type marker) + 0x53 0x75 (smallulong
     * descriptor 0x75 = Data section) + 0xb0 (vbin32) + big-endian 32-bit
     * length + payload. The payload is length-prefixed, so it is
     * binary-safe (null bytes and arbitrary bytes are preserved).
     */
    public static function encodeDataSection(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

    /**
     * Alias of encodeDataSection().
     */
    public static function encode(string $body): string
    {
        return self::encodeDataSection($body);
    }
}
