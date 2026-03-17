<?php

namespace CrazyGoat\RabbitStream\VO;


use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class KeyValue implements FromStreamBufferInterface, ToStreamBufferInterface
{
    public function __construct(private string $key, private string $value)
    {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addString($this->key)
            ->addString($this->value);
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        return new self($buffer->gatString(), $buffer->gatString());
    }
}