<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Trait;

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
