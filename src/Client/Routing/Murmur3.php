<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client\Routing;

/**
 * Pure-PHP MurmurHash3 x86_32 implementation.
 *
 * Used by {@see HashRoutingStrategy} to route super-stream messages to a
 * partition exactly the way the Java and .NET RabbitMQ Stream clients do
 * (seed 104729), so producers written in different languages agree on
 * partition placement for the same routing key.
 *
 * PHP integers are 64-bit, but MurmurHash3 x86_32 is defined entirely in
 * terms of 32-bit unsigned arithmetic; a plain 32x32 multiply in PHP can
 * overflow into a float (silently losing precision) once the product
 * exceeds 2^53. {@see self::mul32()} splits each multiply into two 16-bit-safe
 * halves so every intermediate value stays an exact PHP integer.
 */
final class Murmur3
{
    private const C1 = 0xcc9e2d51;
    private const C2 = 0x1b873593;

    /**
     * Hash $data with MurmurHash3 x86_32, returning an UNSIGNED 32-bit integer
     * (0..4294967295).
     */
    public static function hash32(string $data, int $seed = 0): int
    {
        $length = strlen($data);
        $nblocks = intdiv($length, 4);
        $hash = $seed & 0xffffffff;

        for ($i = 0; $i < $nblocks; $i++) {
            $block = unpack('V', $data, $i * 4);
            if ($block === false) {
                break;
            }
            $k1 = $block[1];
            $hash = self::mixBody($k1, $hash);
        }

        $tailIndex = $nblocks * 4;
        $tailLength = $length & 3;
        $k1 = 0;
        switch ($tailLength) {
            case 3:
                $k1 ^= ord($data[$tailIndex + 2]) << 16;
                // no break
            case 2:
                $k1 ^= ord($data[$tailIndex + 1]) << 8;
                // no break
            case 1:
                $k1 ^= ord($data[$tailIndex]);
                $k1 = self::mul32($k1, self::C1);
                $k1 = self::rotl32($k1, 15);
                $k1 = self::mul32($k1, self::C2);
                $hash ^= $k1;
                break;
        }

        $hash ^= $length;
        $hash = self::fmix32($hash);

        return $hash & 0xffffffff;
    }

    private static function mixBody(int $k1, int $hash): int
    {
        $k1 = self::mul32($k1, self::C1);
        $k1 = self::rotl32($k1, 15);
        $k1 = self::mul32($k1, self::C2);

        $hash ^= $k1;
        $hash = self::rotl32($hash, 13);
        $hash = self::mul32($hash, 5);

        return ($hash + 0xe6546b64) & 0xffffffff;
    }

    private static function fmix32(int $h): int
    {
        $h ^= ($h >> 16) & 0xffffffff;
        $h = self::mul32($h, 0x85ebca6b);
        $h ^= ($h >> 13) & 0xffffffff;
        $h = self::mul32($h, 0xc2b2ae35);
        $h ^= ($h >> 16) & 0xffffffff;
        return $h & 0xffffffff;
    }

    private static function rotl32(int $x, int $r): int
    {
        $x &= 0xffffffff;
        return (($x << $r) | ($x >> (32 - $r))) & 0xffffffff;
    }

    /**
     * 32x32 -> low 32 bits multiply, kept in exact integer math by splitting
     * $a into its low and high 16-bit halves before multiplying.
     */
    private static function mul32(int $a, int $b): int
    {
        return ((($a & 0xffff) * $b) + (((($a >> 16) * $b) & 0xffff) << 16)) & 0xffffffff;
    }
}
