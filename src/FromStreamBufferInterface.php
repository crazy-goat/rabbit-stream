<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Response\ReadBuffer;

interface FromStreamBufferInterface
{
    public static function fromStreamBuffer(ReadBuffer $buffer): ?object;
}