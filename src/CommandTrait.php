<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;

trait CommandTrait
{
    abstract public static function getVersion(): int;
    abstract public static function getKey(): int;

    private static function getKeyVersion(?int $correlationId = null): WriteBuffer
    {
        $buffer = (new WriteBuffer())
            ->addUInt16(self::getKey())
            ->addUInt16(self::getVersion());

        if ($correlationId !== null) {
            $buffer->addUInt32($correlationId);
        }

        return $buffer;
    }

    private static function ValidateKeYVersion(int $key, int $version): void
    {
        if (self::getKey() !== $key) {
            throw new \Exception('Unexpected command code');
        }

        if (self::getVersion() !== $version) {
            throw new \Exception('Unexpected version');
        }
    }

    private static function isResponseCodeOk(int $responseCode): void
    {
        if (ResponseCode::from($responseCode) !== ResponseCode::OK) {
            throw new \Exception('Unexpected response code');
        };
    }
}