<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class CommandVersion implements FromStreamBufferInterface, ToStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private int $key,
        private int $minVersion,
        private int $maxVersion
    ) {
    }

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

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'minVersion' => $this->minVersion,
            'maxVersion' => $this->maxVersion,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['key'], $data['minVersion'], $data['maxVersion']);
    }
}
