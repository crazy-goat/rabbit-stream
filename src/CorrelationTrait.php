<?php

namespace CrazyGoat\StreamyCarrot;

trait CorrelationTrait
{
    private int $correlationId = 0;

    public function getCorrelationId(): int
    {
        return $this->correlationId;
    }

    public function withCorrelationId(int $correlationId): void
    {
        $this->correlationId = $correlationId;
    }
}