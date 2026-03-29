<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\Message;

interface ConsumerInterface
{
    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array;

    public function readOne(float $timeout = 5.0): ?Message;

    public function storeOffset(int $offset): void;

    public function queryOffset(): int;

    public function close(): void;
}
