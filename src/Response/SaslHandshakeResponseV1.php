<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\V1Trait;

class SaslHandshakeResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    private array $mechanisms;

    public function __construct(array $mechanisms)
    {
        $this->mechanisms = $mechanisms;
    }

    public static function getKey(): int
    {
        return KeyEnum::SASL_HANDSHAKE_RESPONSE->value;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        $object = new self($buffer->getStringArray());
        $object->withCorrelationId($correlationId);

        return $object;
    }
}