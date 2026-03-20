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

/** @phpstan-consistent-constructor */
class ResolveOffsetSpecResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    private int $offset = 0;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::assertResponseCodeOk($buffer->getUint16());
        $buffer->getUint16(); // OffsetType: 1 (first), 2 (last), 3 (next), 4 (offset), 5 (timestamp)
        $offset = $buffer->getUint64();

        $object = new static();
        $object->withCorrelationId($correlationId);
        $object->offset = $offset;
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE->value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $object = new static();
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        $object->offset = TypeCast::toInt($data['offset']);
        return $object;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
