<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Trait\Fixtures;

use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Trait\CommandTrait;

class TestCommand
{
    use CommandTrait;

    private static int $testKey = 0x0001;
    private static int $testVersion = 1;

    public static function setKey(int $key): void
    {
        self::$testKey = $key;
    }

    public static function setVersion(int $version): void
    {
        self::$testVersion = $version;
    }

    public static function getKey(): int
    {
        return self::$testKey;
    }

    public static function getVersion(): int
    {
        return self::$testVersion;
    }

    public static function callAssertResponseCodeOk(int $code): void
    {
        self::assertResponseCodeOk($code);
    }

    public static function callValidateKeyVersion(int $key, int $version): void
    {
        self::validateKeyVersion($key, $version);
    }

    public static function callGetKeyVersion(?int $correlationId = null): WriteBuffer
    {
        return self::getKeyVersion($correlationId);
    }
}
