<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\Response\ReadBuffer;
use CrazyGoat\StreamyCarrot\StreamBufferInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\VO\KeyValue;

class PeerPropertiesToStreamBufferV1 extends RequestAbstract implements ToStreamBufferInterface, RequestInterface
{
    private const VERSION = 1;
    private array $keyValues;

    public function __construct(KeyValue ...$keyValues)
    {
        $this->keyValues = $keyValues;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16($this->getCommandCode()->value)
            ->addUInt16(self::VERSION)
            ->addUInt32($this->getCorrelationId())
            ->addArray(...$this->keyValues);
    }

    public function getCommandCode(): CommandCode
    {
        return CommandCode::PEER_PROPERTIES;
    }
}