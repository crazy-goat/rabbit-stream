<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

class SaslAuthenticateResponseV1 extends SimpleCorrelatedResponseV1
{
    private ?string $challenge = null;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        $key = $buffer->getUint16();
        $version = $buffer->getUint16();
        $correlationId = $buffer->getUint32();
        $responseCode = $buffer->getUint16();

        if (static::getKey() !== $key) {
            throw new ProtocolException('Unexpected command code');
        }

        if (static::getVersion() !== $version) {
            throw new ProtocolException('Unexpected version');
        }

        $code = ResponseCodeEnum::tryFrom($responseCode);
        if ($code === null || ($code !== ResponseCodeEnum::OK && $code !== ResponseCodeEnum::SASL_CHALLENGE)) {
            $hex = sprintf('0x%04x', $responseCode);
            $message = $code instanceof ResponseCodeEnum
                ? "{$hex} ({$code->name}: {$code->getMessage()})"
                : "{$hex} (unknown)";
            throw new ProtocolException("Unexpected response code: {$message}", responseCode: $code);
        }

        $object = new static();
        $object->withCorrelationId($correlationId);

        $remaining = $buffer->getRemainingBytes();
        if ($remaining !== '') {
            $challengeBuffer = new ReadBuffer($remaining);
            $object->challenge = $challengeBuffer->getString();
            if ($challengeBuffer->getRemainingBytes() !== '') {
                throw new ProtocolException('Unexpected trailing data in SASL authenticate response');
            }
        }

        return $object;
    }

    public function getChallenge(): ?string
    {
        return $this->challenge;
    }

    public static function getKey(): int
    {
        return KeyEnum::SASL_AUTHENTICATE_RESPONSE->value;
    }
}
