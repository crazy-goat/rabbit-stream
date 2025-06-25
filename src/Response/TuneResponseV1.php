<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;

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
        return KeyEnum::TUNE_RESPONSE->value;
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