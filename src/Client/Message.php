<?php

namespace CrazyGoat\RabbitStream\Client;

class Message
{
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

    public function getBody(): string|int|float|bool|array|null
    {
        return $this->body;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getApplicationProperties(): array
    {
        return $this->applicationProperties;
    }

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
        return $this->properties['content-type'] ?? null;
    }

    public function getSubject(): ?string
    {
        return $this->properties['subject'] ?? null;
    }

    public function getCreationTime(): ?int
    {
        return $this->properties['creation-time'] ?? null;
    }

    public function getGroupId(): ?string
    {
        return $this->properties['group-id'] ?? null;
    }
}
