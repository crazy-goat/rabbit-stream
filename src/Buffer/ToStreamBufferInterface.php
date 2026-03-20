<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

interface ToStreamBufferInterface
{
    public function toStreamBuffer(): WriteBuffer;
}
