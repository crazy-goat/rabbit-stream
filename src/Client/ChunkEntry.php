<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class ChunkEntry
{
    public function __construct(
        private readonly int $offset,
        private readonly string $data,
        private readonly int $timestamp,
    ) {
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
