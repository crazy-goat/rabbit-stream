<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class PublishedMessageV2 implements ToStreamBufferInterface
{
    public function __construct(
        private int $publishingId,
        private string $filterValue,
        private string $message,
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt64($this->publishingId)
            ->addString($this->filterValue)
            ->addBytes($this->message);
    }

    public function getPublishingId(): int
    {
        return $this->publishingId;
    }
}
