<?php

namespace CrazyGoat\RabbitStream\Trait;

interface KeyVersionInterface
{
    public static function getVersion(): int;
    public static function getKey(): int;
}