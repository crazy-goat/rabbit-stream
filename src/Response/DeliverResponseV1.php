<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class DeliverResponseV1 implements KeyVersionInterface, FromStreamBufferInterface
{
    use CommandTrait;
    use V1Trait;

    public function __construct(private int $subscriptionId, private string $chunkBytes) {}

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
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $subscriptionId = $buffer->getUint8();
        $chunkBytes = $buffer->getRemainingBytes();
        return new self($subscriptionId, $chunkBytes);
    }

    static public function getKey(): int
    {
        return KeyEnum::DELIVER->value;
    }
}
