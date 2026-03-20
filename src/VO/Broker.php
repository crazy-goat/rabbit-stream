<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Util\TypeCast;

/** @phpstan-consistent-constructor */
class Broker implements FromStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private readonly int $reference,
        private readonly string $host,
        private readonly int $port
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

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        return new static(
            $buffer->getUint16(),
            $buffer->getString() ?? '',
            $buffer->getUint32()
        );
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'host' => $this->host,
            'port' => $this->port,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            TypeCast::toInt($data['reference']),
            TypeCast::toString($data['host']),
            TypeCast::toInt($data['port'])
        );
    }
}
