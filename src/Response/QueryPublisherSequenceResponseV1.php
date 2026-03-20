<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class QueryPublisherSequenceResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    private int $sequence = 0;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());
        $sequence = $buffer->getUint64();

        $object = new self();
        $object->withCorrelationId($correlationId);
        $object->sequence = $sequence;
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::QUERY_PUBLISHER_SEQUENCE_RESPONSE->value;
    }

    public static function fromArray(array $data): static
    {
        $object = new self();
        $object->withCorrelationId($data['correlationId']);
        $object->sequence = $data['sequence'];
        return $object;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }
}
