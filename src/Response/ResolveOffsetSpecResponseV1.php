<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class ResolveOffsetSpecResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    private int $offset = 0;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());
        $offsetType = $buffer->getUint16(); // OffsetType: 1 (first), 2 (last), 3 (next), 4 (offset), 5 (timestamp)
        $offset = $buffer->getUint64();

        $object = new self();
        $object->withCorrelationId($correlationId);
        $object->offset = $offset;
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE->value;
    }

    public static function fromArray(array $data): static
    {
        $object = new self();
        $object->withCorrelationId($data['correlationId']);
        $object->offset = $data['offset'];
        return $object;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
