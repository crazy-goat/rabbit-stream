<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;
use CrazyGoat\StreamyCarrot\Trait\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

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