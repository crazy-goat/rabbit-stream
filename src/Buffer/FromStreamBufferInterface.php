<?php

namespace CrazyGoat\StreamyCarrot\Buffer;

interface FromStreamBufferInterface
{
    public static function fromStreamBuffer(ReadBuffer $buffer): ?object;
}