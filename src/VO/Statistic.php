<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class Statistic implements FromStreamBufferInterface, ToStreamBufferInterface
{
    public function __construct(private string $key, private int $value)
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

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        return new self($buffer->gatString(), $buffer->getInt64());
    }
}
