<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;

class Broker implements FromStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private int $reference,
        private string $host,
        private int $port
    ) {
    }

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
            $buffer->getString(),
            $buffer->getUint32()
        );
    }

    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'host' => $this->host,
            'port' => $this->port,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['reference'], $data['host'], $data['port']);
    }
}
