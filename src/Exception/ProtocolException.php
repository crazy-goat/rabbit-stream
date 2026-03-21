<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

class ProtocolException extends RabbitStreamException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?ResponseCodeEnum $responseCode = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getResponseCode(): ?ResponseCodeEnum
    {
        return $this->responseCode;
    }
}
