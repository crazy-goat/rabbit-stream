<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class CreditResponseV1 implements KeyVersionInterface, FromStreamBufferInterface
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

    static public function getKey(): int
    {
        return KeyEnum::CREDIT_RESPONSE->value;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
