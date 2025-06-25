<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;

interface ToStreamBufferInterface
{
    public function toStreamBuffer(): WriteBuffer;
}