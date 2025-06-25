<?php

namespace CrazyGoat\StreamyCarrot\VO;


use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;

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