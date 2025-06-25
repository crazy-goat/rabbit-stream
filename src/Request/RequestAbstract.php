<?php

namespace CrazyGoat\StreamyCarrot\Request;

abstract class RequestAbstract
{
    private ?int $correlationId = null;

    public function withCorrelationId(int $correlationId): void
    {
        $this->correlationId = $correlationId;
    }
    public function getCorrelationId(): int
    {
        if ($this->correlationId === null) {
            throw new \Exception("CorrelationId not set");
        }
        return $this->correlationId;
    }
}