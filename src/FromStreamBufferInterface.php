<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;

interface FromStreamBufferInterface
{
    public static function fromStreamBuffer(ReadBuffer $buffer): ?object;
}