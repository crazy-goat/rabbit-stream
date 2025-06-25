<?php

namespace CrazyGoat\StreamyCarrot;

interface KeyVersionInterface
{
    public static function getVersion(): int;
    public static function getKey(): int;
}