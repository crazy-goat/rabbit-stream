<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Request\WriteBuffer;
use CrazyGoat\StreamyCarrot\Response\ReadBuffer;

interface ToStreamBufferInterface
{
    public function toStreamBuffer(): WriteBuffer;
}