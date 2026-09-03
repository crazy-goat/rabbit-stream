<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Trait;

use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

trait CommandTrait
{
    abstract public static function getVersion(): int;
    abstract public static function getKey(): int;

    /**
     * Build the key+version(+correlationId) header in one pack() call instead
     * of three fluent WriteBuffer::addUIntX() calls (each of which pays for a
     * range-validation branch and a separate pack()/concat). Key and version
     * are protocol-defined constants, always in range, so only the caller-
     * supplied correlationId still needs bounds checking.
     */
    private static function getKeyVersion(?int $correlationId = null): WriteBuffer
    {
        if ($correlationId === null) {
            return new WriteBuffer(pack('nn', self::getKey(), self::getVersion()));
        }

        if ($correlationId < 0 || $correlationId > 4294967295) {
            throw new InvalidArgumentException(
                "Value {$correlationId} is out of range for uint32 (0 to 4294967295)"
            );
        }

        return new WriteBuffer(pack('nnN', self::getKey(), self::getVersion(), $correlationId));
    }

    private static function validateKeyVersion(int $key, int $version): void
    {
        if (self::getKey() !== $key) {
            throw new ProtocolException('Unexpected command code');
        }

        if (self::getVersion() !== $version) {
            throw new ProtocolException('Unexpected version');
        }
    }

    private static function assertResponseCodeOk(int $responseCode): void
    {
        $code = ResponseCodeEnum::tryFrom($responseCode);
        if ($code === null || $code !== ResponseCodeEnum::OK) {
            $hex = sprintf('0x%04x', $responseCode);
            $msg = $code instanceof ResponseCodeEnum
                ? "{$hex} ({$code->name}: {$code->getMessage()})"
                : "{$hex} (unknown)";
            throw new ProtocolException("Unexpected response code: {$msg}", responseCode: $code);
        }
    }
}
