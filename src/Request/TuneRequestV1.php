<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class TuneRequestV1 implements FromStreamBufferInterface, ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(private int $frameMax = 0, private int $heartbeat = 0)
    {
    }

    public static function getKey(): int
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

    public function toArray(): array
    {
        return [
            'frameMax' => $this->frameMax,
            'heartbeat' => $this->heartbeat,
        ];
    }
}