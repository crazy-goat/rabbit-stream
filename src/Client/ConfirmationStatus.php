<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class ConfirmationStatus
{
    public function __construct(
        private readonly bool $confirmed,
        private readonly ?int $errorCode = null,
        private readonly ?int $publishingId = null,
    ) {
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    public function getPublishingId(): ?int
    {
        return $this->publishingId;
    }
}
