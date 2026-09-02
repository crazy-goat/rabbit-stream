<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Exception\LengthException;

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
     *
     * @throws LengthException when the payload exceeds the AMQP vbin32
     *                          maximum of 4294967295 bytes, since the
     *                          32-bit length prefix could not represent it
     *                          and silent truncation would corrupt framing.
     */
    public static function encodeDataSection(string $body): string
    {
        if (strlen($body) > 0xFFFFFFFF) {
            throw new LengthException(
                'AMQP 1.0 Data section payload exceeds the 4294967295-byte vbin32 limit'
            );
        }

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
