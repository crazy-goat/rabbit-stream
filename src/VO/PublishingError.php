<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;

class PublishingError implements ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private int $publishingId,
        private int $code,
    ) {
    }

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

    public function toArray(): array
    {
        return ['publishingId' => $this->publishingId, 'code' => $this->code];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['publishingId'], $data['code']);
    }
}
