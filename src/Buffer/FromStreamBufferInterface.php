<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

interface FromStreamBufferInterface
{
    public static function fromStreamBuffer(ReadBuffer $buffer): ?object;
}
