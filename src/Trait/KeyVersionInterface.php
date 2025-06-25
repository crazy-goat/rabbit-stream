<?php

namespace CrazyGoat\StreamyCarrot\Trait;

interface KeyVersionInterface
{
    public static function getVersion(): int;
    public static function getKey(): int;
}