<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

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
