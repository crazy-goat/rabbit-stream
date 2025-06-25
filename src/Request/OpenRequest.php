<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\CorrelationTrait;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\V1Trait;

class OpenRequest implements KeyVersionInterface, ToStreamBufferInterface, CorrelationInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(private string $vhost = '/')
    {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addString($this->vhost);
    }

    static public function getKey(): int
    {
        return CommandCode::OPEN->value;
    }
}