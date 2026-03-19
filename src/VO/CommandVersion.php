<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class CommandVersion implements FromStreamBufferInterface, ToStreamBufferInterface
{
    public function __construct(
        private int $key,
        private int $minVersion,
        private int $maxVersion
    ) {}

    public function getKey(): int
    {
        return $this->key;
    }

    public function getMinVersion(): int
    {
        return $this->minVersion;
    }

    public function getMaxVersion(): int
    {
        return $this->maxVersion;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        return new self(
            $buffer->getUint16(),
            $buffer->getUint16(),
            $buffer->getUint16()
        );
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16($this->key)
            ->addUInt16($this->minVersion)
            ->addUInt16($this->maxVersion);
    }
}
