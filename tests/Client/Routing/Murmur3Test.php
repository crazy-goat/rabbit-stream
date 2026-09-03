<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client\Routing;

use CrazyGoat\RabbitStream\Client\Routing\Murmur3;
use PHPUnit\Framework\TestCase;

class Murmur3Test extends TestCase
{
    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function seedZeroVectors(): array
    {
        return [
            'hello' => ['hello', 0, 613153351],
            'quick brown fox' => ['The quick brown fox jumps over the lazy dog', 0, 0x2e4ff723],
            'empty string' => ['', 0, 0],
        ];
    }

    /** @dataProvider seedZeroVectors */
    public function testHash32SeedZero(string $input, int $seed, int $expected): void
    {
        $this->assertSame($expected, Murmur3::hash32($input, $seed));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function seed104729Vectors(): array
    {
        return [
            'hello' => ['hello', 1321743225],
            'brave' => ['brave', 3825276426],
            'new' => ['new', 2970740106],
            'world' => ['world', 2453398188],
            'empty string' => ['', 3329588566],
            'a' => ['a', 1086686554],
            'ab' => ['ab', 398604456],
            'abc' => ['abc', 3409700625],
            'abcd' => ['abcd', 3421720556],
            'key-0' => ['key-0', 4024216190],
            'key-1' => ['key-1', 899319889],
            'key-42' => ['key-42', 1791113265],
        ];
    }

    /** @dataProvider seed104729Vectors */
    public function testHash32Seed104729(string $input, int $expected): void
    {
        $this->assertSame($expected, Murmur3::hash32($input, 104729));
    }
}
