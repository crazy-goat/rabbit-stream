<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;
use CrazyGoat\StreamyCarrot\Trait\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class ConsumerUpdateQueryV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CommandTrait;
    use CorrelationTrait;
    use V1Trait;

    public function __construct(private int $subscriptionId, private bool $active) {}

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        $subscriptionId = $buffer->getUint8();
        $active = $buffer->getUint8() === 1;
        $object = new self($subscriptionId, $active);
        $object->withCorrelationId($correlationId);
        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::CONSUMER_UPDATE->value;
    }
}
