<?php

namespace CrazyGoat\StreamyCarrot\Buffer;

interface ToStreamBufferInterface
{
    public function toStreamBuffer(): WriteBuffer;
}