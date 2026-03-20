<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class PublishedMessage implements ToStreamBufferInterface, ToArrayInterface
{
    public function __construct(
        private int $publishingId,
        private string $message,
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

    public function toArray(): array
    {
        return ['publishingId' => $this->publishingId, 'data' => $this->message];
    }
}
