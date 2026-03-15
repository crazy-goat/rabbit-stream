<?php

namespace CrazyGoat\StreamyCarrot\VO;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;

class PublishedMessage implements ToStreamBufferInterface
{
    public function __construct(
        private int $publishingId,
        private string $message,
    ) {}

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
}
