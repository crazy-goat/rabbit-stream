<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;

class OpenRequest implements KeyVersionInterface, ToStreamBufferInterface, CorrelationInterface
{
    private int $correlationId;

    public function __construct(private string $vhost = '/')
    {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16($this->getKey())
            ->addUInt16($this->getVersion())
            ->addUInt32($this->getCorrelationId())
            ->addString($this->vhost);
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getKey(): int
    {
        return CommandCode::OPEN->value;
    }

    public function getCorrelationId(): int
    {
        return $this->correlationId;
    }

    public function withCorrelationId(int $correlationId): void
    {
        $this->correlationId = $correlationId;
    }
}