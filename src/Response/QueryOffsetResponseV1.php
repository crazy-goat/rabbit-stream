<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/** @phpstan-consistent-constructor */
class QueryOffsetResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * The stored offset, or null when the broker answered `NO_OFFSET` (0x13) —
     * the normal reply for a reference with no tracking record yet (#467).
     */
    private ?int $offset = null;

    /**
     * Parse a QueryOffset reply.
     *
     * `NO_OFFSET` is a normal answer, not an error: it means nothing has been
     * stored for this reference/stream pair (yet). It is represented as a
     * response carrying a `null` offset rather than an exception, so the
     * first-run resume flow works without a try/catch. Every other non-OK code
     * still raises a ProtocolException from assertResponseCodeOk().
     */
    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        $responseCode = $buffer->getUint16();

        $object = new static();
        $object->withCorrelationId($correlationId);

        if ($responseCode === ResponseCodeEnum::NO_OFFSET->value) {
            $object->offset = null;
            return $object;
        }

        self::assertResponseCodeOk($responseCode);
        $object->offset = $buffer->getUint64();
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::QUERY_OFFSET_RESPONSE->value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $object = new static();
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        $offset = $data['offset'] ?? null;
        $object->offset = $offset === null ? null : TypeCast::toInt($offset);
        return $object;
    }

    /**
     * The stored next offset to consume, or null when no offset has been stored
     * for this reference/stream pair (the broker answered NO_OFFSET).
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }
}
