<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\V1Trait;

class SaslHandshakeRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId());
    }

    static public function getKey(): int
    {
        return KeyEnum::SASL_HANDSHAKE->value;
    }
}