<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

interface FromArrayInterface
{
    public static function fromArray(array $data): static;
}
