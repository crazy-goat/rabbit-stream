<?php

namespace CrazyGoat\StreamyCarrot\Trait;

interface CorrelationInterface
{
    public function getCorrelationId(): int;
    public function withCorrelationId(int $correlationId): void;
}