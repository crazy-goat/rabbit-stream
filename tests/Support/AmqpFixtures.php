<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Support;

/**
 * Hand-built AMQP 1.0 byte fixtures shared by the tests that exercise decoding
 * limits from several entry points (decoder, Message, chunk parser, Consumer).
 */
final class AmqpFixtures
{
    /**
     * A list8 chain $depth lists deep: each list holds a single child, the
     * innermost holds null. Every `size` is spec-exact (the count byte plus the
     * content bytes), which the decoder insists on since #453.
     */
    public static function nestedList8(int $depth): string
    {
        $payload = "\x40"; // null
        for ($i = 0; $i < $depth; $i++) {
            $size = strlen($payload) + 1; // size includes the count byte
            $payload = "\xc0" . chr($size & 0xFF) . "\x01" . $payload;
        }

        return $payload;
    }

    /**
     * An AMQP message whose body is an AmqpValue section (descriptor 0x76)
     * holding {@see self::nestedList8()} — the shape used to probe the recursion
     * depth limit through the lazy-decode path.
     */
    public static function messageWithNestedBody(int $depth): string
    {
        return "\x00\x53\x76" . self::nestedList8($depth);
    }
}
