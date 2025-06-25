<?php

namespace CrazyGoat\StreamyCarrot;

interface CorrelationInterface
{
    public function getCorrelationId(): int;
    public function withCorrelationId(int $correlationId): void;
}