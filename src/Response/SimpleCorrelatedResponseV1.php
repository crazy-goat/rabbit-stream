<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/**
 * Abstract base class for simple correlated responses.
 *
 * Responses that only contain key, version, correlationId, and responseCode
 * can extend this class to inherit common deserialization logic.
 *
 * @phpstan-consistent-constructor
 */
abstract class SimpleCorrelatedResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use V1Trait;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        $key = $buffer->getUint16();
        $version = $buffer->getUint16();
        $correlationId = $buffer->getUint32();
        $responseCode = $buffer->getUint16();

        // Validate key matches expected value for concrete class
        if (static::getKey() !== $key) {
            throw new ProtocolException('Unexpected command code');
        }

        // Validate version matches expected value
        if (static::getVersion() !== $version) {
            throw new ProtocolException('Unexpected version');
        }

        // Validate response code is OK
        $code = ResponseCodeEnum::tryFrom($responseCode);
        if ($code === null || $code !== ResponseCodeEnum::OK) {
            $hex = sprintf('0x%04x', $responseCode);
            $msg = $code instanceof ResponseCodeEnum
                ? "{$hex} ({$code->name}: {$code->getMessage()})"
                : "{$hex} (unknown)";
            throw new ProtocolException("Unexpected response code: {$msg}", responseCode: $code);
        }

        $object = new static();
        $object->withCorrelationId($correlationId);
        return $object;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $object = new static();
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }
}
