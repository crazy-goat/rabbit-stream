<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class PublishedMessage implements ToStreamBufferInterface, ToArrayInterface
{
    public function __construct(
        private readonly int $publishingId,
        private readonly string $message,
    ) {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt64($this->publishingId)
            ->addBytes($this->message);
    }

    public function getPublishingId(): int
    {
        return $this->publishingId;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return ['publishingId' => $this->publishingId, 'data' => $this->message];
    }
}
