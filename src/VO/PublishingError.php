<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

class PublishingError
{
    public function __construct(
        private int $publishingId,
        private int $code,
    ) {}

    public function getPublishingId(): int
    {
        return $this->publishingId;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): self
    {
        return new self($buffer->getUint64(), $buffer->getUint16());
    }
}
