<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Util\TypeCast;

/** @phpstan-consistent-constructor */
class CommandVersion implements FromStreamBufferInterface, ToStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private readonly int $key,
        private readonly int $minVersion,
        private readonly int $maxVersion
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

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        return new static(
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

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'minVersion' => $this->minVersion,
            'maxVersion' => $this->maxVersion,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            TypeCast::toInt($data['key']),
            TypeCast::toInt($data['minVersion']),
            TypeCast::toInt($data['maxVersion'])
        );
    }
}
