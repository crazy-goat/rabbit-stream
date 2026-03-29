<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/**
 * Server-initiated query frame asking the client for the current offset.
 *
 * This is a server-push frame (not a response to a client request) that is sent
 * when the server needs to know the consumer's current offset position.
 * The client must reply with a ConsumerUpdateReplyV1.
 *
 * @phpstan-consistent-constructor
 */
class ConsumerUpdateResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    public function __construct(private int $subscriptionId, private bool $active)
    {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        $subscriptionId = $buffer->getUint8();
        $active = $buffer->getUint8() === 1;
        $object = new static($subscriptionId, $active);
        $object->withCorrelationId($correlationId);
        return $object;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $object = new static(TypeCast::toInt($data['subscriptionId']), TypeCast::toBool($data['active']));
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::CONSUMER_UPDATE->value;
    }
}
