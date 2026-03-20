<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

/** @phpstan-consistent-constructor */
class Statistic implements FromStreamBufferInterface, ToStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(private readonly string $key, private readonly int $value)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addString($this->key)
            ->addInt64($this->value);
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        return new static($buffer->getString(), $buffer->getInt64());
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static($data['key'], $data['value']);
    }
}
