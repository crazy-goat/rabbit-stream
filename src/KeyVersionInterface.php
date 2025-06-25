<?php

namespace CrazyGoat\StreamyCarrot;

interface KeyVersionInterface
{
    public function getVersion(): int;
    public function getKey(): int;
}