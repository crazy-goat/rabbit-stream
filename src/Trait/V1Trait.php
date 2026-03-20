<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Trait;

trait V1Trait
{
    public static function getVersion(): int
    {
        return 1;
    }
}
