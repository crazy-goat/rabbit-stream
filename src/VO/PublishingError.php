<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;

/** @phpstan-consistent-constructor */
class PublishingError implements ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private readonly int $publishingId,
        private readonly int $code,
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
        return new static($buffer->getUint64(), $buffer->getUint16());
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['publishingId' => $this->publishingId, 'code' => $this->code];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static($data['publishingId'], $data['code']);
    }
}
