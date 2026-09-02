<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream;

use CrazyGoat\RabbitStream\Exception\UnsupportedPlatformException;

/**
 * Runtime requirements that composer.json cannot express.
 *
 * composer requires `php: >=8.1` but has no way to require a 64-bit build, so
 * the check lives here and is made by every entry point that decodes wire data
 * (see {@see Buffer\ReadBuffer::__construct()} and
 * {@see Client\AmqpDecoder::decodeValue()}).
 */
final class Platform
{
    /**
     * Refuse to decode wire data on a 32-bit PHP build.
     *
     * The protocol carries uint32 and int64/uint64 fields — offsets, timestamps,
     * chunk sizes, AMQP long/timestamp values — and on a 32-bit build
     * `unpack('N')`/`unpack('J')` return a float for anything above
     * `PHP_INT_MAX`, silently violating the `int` return types and losing
     * precision on exactly the values (offsets!) that must stay exact. Making
     * uint64 work there would need string-based big-integer math, which is
     * disproportionate for a platform that is vanishingly rare on PHP 8.1+, so
     * the library fails loudly instead of quietly returning wrong offsets (#458).
     *
     * `PHP_INT_SIZE` is a compile-time constant, so this costs a single
     * comparison against an immediate — safe to call on hot paths.
     */
    public static function assertSixtyFourBitIntegers(): void
    {
        if (PHP_INT_SIZE >= 8) {
            return;
        }

        throw new UnsupportedPlatformException(sprintf(
            'rabbit-stream requires a 64-bit PHP build: this one has %d-byte integers, '
            . 'so the protocol\'s uint32/uint64 fields (offsets, timestamps, chunk sizes) '
            . 'cannot be represented exactly.',
            PHP_INT_SIZE
        ));
    }
}
