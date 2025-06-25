<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class TuneRequestV1 implements FromStreamBufferInterface, ToStreamBufferInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(private int $frameMax = 0, private int $heartbeat = 0)
    {
    }

    static public function getKey(): int
    {
        return KeyEnum::TUNE->value;
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