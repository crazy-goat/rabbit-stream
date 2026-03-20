<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class Message
{
    /**
     * @param array<int, mixed>|string|int|float|bool|null $body
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $applicationProperties
     * @param array<string, mixed> $messageAnnotations
     */
    public function __construct(
        private readonly int $offset,
        private readonly int $timestamp,
        private readonly string|int|float|bool|array|null $body,
        private readonly array $properties = [],
        private readonly array $applicationProperties = [],
        private readonly array $messageAnnotations = [],
    ) {
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /** @return array<int, mixed>|string|int|float|bool|null */
    public function getBody(): string|int|float|bool|array|null
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /** @return array<string, mixed> */
    public function getApplicationProperties(): array
    {
        return $this->applicationProperties;
    }

    /** @return array<string, mixed> */
    public function getMessageAnnotations(): array
    {
        return $this->messageAnnotations;
    }

    public function getMessageId(): mixed
    {
        return $this->properties['message-id'] ?? null;
    }

    public function getCorrelationId(): mixed
    {
        return $this->properties['correlation-id'] ?? null;
    }

    public function getContentType(): ?string
    {
        $value = $this->properties['content-type'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    public function getSubject(): ?string
    {
        $value = $this->properties['subject'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    public function getCreationTime(): ?int
    {
        $value = $this->properties['creation-time'] ?? null;
        return is_scalar($value) ? (int) $value : null;
    }

    public function getGroupId(): ?string
    {
        $value = $this->properties['group-id'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }
}
