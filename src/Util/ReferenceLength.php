<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Util;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

/**
 * Checks the length of a reference before it is sent to the broker.
 *
 * A reference is a producer name, a consumer name, or the name in StoreOffset
 * and QueryOffset. The broker rejects a reference over 255 bytes and does not
 * name the limit in its answer, so the client checks the length first.
 */
final class ReferenceLength
{
    /** Longest reference the broker accepts, in bytes. */
    public const MAX_BYTES = 255;

    /**
     * @param string $reference The value that goes to the broker.
     * @param string $role      What the value is, for the message (for example "producer name").
     * @throws InvalidArgumentException If the reference is longer than MAX_BYTES bytes.
     */
    public static function assertValid(string $reference, string $role): void
    {
        $length = strlen($reference);
        if ($length > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The %s is %d bytes long; the broker accepts at most %d bytes.',
                $role,
                $length,
                self::MAX_BYTES
            ));
        }
    }
}
