<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

interface CorrelationInterface
{
    public function getCorrelationId(): int;
    public function withCorrelationId(int $correlationId): void;
}
