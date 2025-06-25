<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Request\WriteBuffer;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;

class TuneResponseV1 implements KeyVersionInterface, ToStreamBufferInterface
{
    public function __construct(private int $frameMax = 0, private int $heartbeat = 0)
    {
    }

    static public function getVersion(): int
    {
        return 1;
    }

    static public function getKey(): int
    {
        return CommandCode::TUNE_RESPONSE->value;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16(self::getKey())
            ->addUInt16(self::getVersion())
            ->addUInt32($this->frameMax)
            ->addUInt32($this->heartbeat);
    }
}