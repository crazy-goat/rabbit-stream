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

/** @phpstan-consistent-constructor */
class CreditResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    private int $responseCode;
    private int $subscriptionId;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $object = new self();
        $object->responseCode = $buffer->getUint16();
        $object->subscriptionId = $buffer->getUInt8();
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::CREDIT_RESPONSE->value;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    public static function fromArray(array $data): static
    {
        $object = new static();
        $object->responseCode = $data['responseCode'];
        $object->subscriptionId = $data['subscriptionId'];
        return $object;
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
