<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

class Broker implements FromStreamBufferInterface
{
    public function __construct(
        private int $reference,
        private string $host,
        private int $port
    ) {}

    public function getReference(): int
    {
        return $this->reference;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        return new self(
            $buffer->getUint16(),
            $buffer->gatString(),
            $buffer->getUint32()
        );
    }
}
