<?php

namespace CrazyGoat\RabbitStream\Buffer;

interface FromStreamBufferInterface
{
    public static function fromStreamBuffer(ReadBuffer $buffer): ?object;
}
