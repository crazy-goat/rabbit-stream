<?php

namespace CrazyGoat\RabbitStream\Contract;

interface KeyVersionInterface
{
    public static function getVersion(): int;
    public static function getKey(): int;
}
