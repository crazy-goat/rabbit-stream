<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

interface FromArrayInterface
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static;
}
