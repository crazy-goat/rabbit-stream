<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Exception\DeserializationException;
use WeakMap;

/**
 * Holds one credential so that it stays out of dumps, serialized data and traces.
 *
 * The value lives in a static WeakMap, not in a property. Because of this,
 * var_export(), serialize(), json_encode(), the (array) cast, print_r() and
 * var_dump() of the holder show no value. Only reveal() returns it.
 *
 * @internal
 */
final class Secret
{
    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /** @return array<string, string> */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<string, string> $data */
    public function __unserialize(array $data): void
    {
        throw new DeserializationException(
            'A credential is not serialized, so it cannot be restored. Create a new object with the credential.'
        );
    }
}
