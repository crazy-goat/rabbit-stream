<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

interface ToArrayInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
