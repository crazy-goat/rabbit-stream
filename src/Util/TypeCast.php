<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Util;

/**
 * Type-safe extraction helpers for values from mixed arrays.
 *
 * These methods properly narrow `mixed` values before casting,
 * satisfying PHPStan level 9 requirements.
 */
final class TypeCast
{
    public static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || is_string($value) || is_bool($value)) {
            return (int) $value;
        }
        return 0;
    }

    public static function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value) || is_string($value) || is_bool($value)) {
            return (float) $value;
        }
        return 0.0;
    }

    public static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    public static function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::toString($value);
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (bool) $value;
        }
        return false;
    }

    /**
     * @return array<int, int>
     */
    public static function toIntArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::toInt($item);
        }
        return $result;
    }

    /**
     * @return array<int, string>
     */
    public static function toStringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::toString($item);
        }
        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    public static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        return [];
    }
}
