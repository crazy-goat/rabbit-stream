<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\V1Trait;

class TuneRequestV1 implements FromStreamBufferInterface, ToStreamBufferInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(private int $frameMax = 0, private int $heartbeat = 0)
    {
    }

    static public function getKey(): int
    {
        return CommandCode::TUNE->value;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeYVersion()
            ->addUInt32($this->frameMax)
            ->addUInt32($this->heartbeat);
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        return new self($buffer->getUint32(), $buffer->getUint32());
    }

    public function getFrameMax(): int
    {
        return $this->frameMax;
    }

    public function getHeartbeat(): int
    {
        return $this->heartbeat;
    }
}