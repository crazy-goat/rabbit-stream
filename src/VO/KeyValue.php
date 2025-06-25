<?php

namespace CrazyGoat\StreamyCarrot\VO;


use CrazyGoat\StreamyCarrot\Request\WriteBuffer;
use CrazyGoat\StreamyCarrot\Response\ReadBuffer;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;

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