<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/** @phpstan-consistent-constructor */
class DeliverResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    public function __construct(private int $subscriptionId, private string $chunkBytes)
    {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function getChunkBytes(): string
    {
        return $this->chunkBytes;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        $key = $buffer->getUint16();
        $version = $buffer->getUint16();

        if (self::getKey() !== $key) {
            throw new ProtocolException('Unexpected command code');
        }

        $subscriptionId = $buffer->getUint8();

        // Deliver v2 has CommittedChunkId (uint64) before OsirisChunk
        if ($version === 2) {
            $buffer->skip(8);
        }

        $chunkBytes = $buffer->getRemainingBytes();
        return new static($subscriptionId, $chunkBytes);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(TypeCast::toInt($data['subscriptionId']), TypeCast::toString($data['chunkBytes']));
    }

    public static function getKey(): int
    {
        return KeyEnum::DELIVER->value;
    }
}
