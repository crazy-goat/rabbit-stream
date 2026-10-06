<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

/**
 * A protocol command identified by its command key and version.
 *
 * Every request and response class implements this: the key selects the
 * command (and, with bit 15 set, its response form — see
 * {@see \CrazyGoat\RabbitStream\Enum\KeyEnum}), while the version selects the
 * frame layout for that command. The values are compile-time constants, which
 * is why the accessors are static.
 */
interface KeyVersionInterface
{
    /**
     * The protocol version this class serializes or parses.
     *
     * @return int Version number; 1 for every current `*V1` class.
     */
    public static function getVersion(): int;

    /**
     * The protocol command key this class represents.
     *
     * @return int Command key from {@see \CrazyGoat\RabbitStream\Enum\KeyEnum}
     *             (for example `0x0001` for DeclarePublisher).
     */
    public static function getKey(): int;
}
