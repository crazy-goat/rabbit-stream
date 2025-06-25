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

class SaslAuthenticateRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(private string $mechanism, private string $username, private string $password)
    {
    }
    public function toStreamBuffer(): WriteBuffer
    {
        return  self::getKeYVersion($this->getCorrelationId())
            ->addString($this->mechanism)
            ->addBytes("\0" . $this->username . "\0" . $this->password);
    }

    static public function getKey(): int
    {
        return KeyEnum::SASL_AUTHENTICATE->value;
    }
}