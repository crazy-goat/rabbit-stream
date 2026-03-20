<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

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

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        $key = $buffer->getUint16();
        $version = $buffer->getUint16();
        self::validateKeyVersion($key, $version);
        $subscriptionId = $buffer->getUint8();

        // Deliver v2 has CommittedChunkId (uint64) before OsirisChunk
        if ($version === 2) {
            $buffer->skip(8);
        }

        $chunkBytes = $buffer->getRemainingBytes();
        return new self($subscriptionId, $chunkBytes);
    }

    public static function fromArray(array $data): static
    {
        return new self($data['subscriptionId'], $data['chunkBytes']);
    }

    public static function getKey(): int
    {
        return KeyEnum::DELIVER->value;
    }
}
