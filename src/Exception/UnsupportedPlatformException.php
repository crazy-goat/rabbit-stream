<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * The PHP runtime cannot represent the values this library reads off the wire.
 *
 * Thrown when the library is running on a 32-bit PHP build: the RabbitMQ Stream
 * protocol carries uint32 and int64/uint64 fields (offsets, timestamps, chunk
 * sizes, AMQP long/timestamp values), and `unpack('N')`/`unpack('J')` return a
 * float — silently losing precision and breaking the declared `int` types — for
 * anything above `PHP_INT_MAX`. Rather than hand back quietly wrong offsets,
 * every entry point that decodes wire data refuses to run (#458).
 */
class UnsupportedPlatformException extends RabbitStreamException
{
}
