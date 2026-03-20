<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

interface ToArrayInterface
{
    public function toArray(): array;
}
