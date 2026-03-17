<?php

namespace CrazyGoat\RabbitStream\Buffer;

interface ToStreamBufferInterface
{
    public function toStreamBuffer(): WriteBuffer;
}