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
class KeyValue implements FromStreamBufferInterface, ToStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(private readonly string $key, private readonly ?string $value)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addString($this->key)
            ->addString($this->value);
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        return new static($buffer->getString() ?? '', $buffer->getString());
    }

    /** @return array<string, string|null> */
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
