<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class ConsumerUpdateReplyV1 implements
    ToStreamBufferInterface,
    ToArrayInterface,
    CorrelationInterface,
    KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private int $responseCode,
        private int $offsetType,
        private int $offset,
    ) {
        if ($offsetType < 0 || $offsetType > OffsetSpec::TYPE_TIMESTAMP) {
            throw new InvalidArgumentException(
                "Invalid offset type {$offsetType}: expected 0-5 (none/first/last/next/offset/timestamp)"
            );
        }

        if ($offsetType < OffsetSpec::TYPE_OFFSET && $offset !== 0) {
            throw new InvalidArgumentException(
                "Offset type {$offsetType} does not accept a non-zero offset"
            );
        }
    }

    public function toStreamBuffer(): WriteBuffer
    {
        $buffer = self::getKeyVersion($this->getCorrelationId())
            ->addUInt16($this->responseCode)
            ->addUInt16($this->offsetType);

        // Only offset types with a value (4 = offset, 5 = timestamp) carry the
        // 8-byte value; types 0-3 (none/first/last/next) encode just the type.
        if ($this->offsetType === OffsetSpec::TYPE_OFFSET) {
            $buffer->addUInt64($this->offset);
        } elseif ($this->offsetType === OffsetSpec::TYPE_TIMESTAMP) {
            // PROTOCOL.adoc (ConsumerUpdateResponse): the value is uint64 for
            // offset and int64 for timestamp, so a pre-1970 (negative)
            // timestamp is encoded as two's complement — the same bytes
            // OffsetSpec::toStreamBuffer() writes for the same spec.
            $buffer->addInt64($this->offset);
        }

        return $buffer;
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        $hasValue = $this->offsetType === OffsetSpec::TYPE_OFFSET
            || $this->offsetType === OffsetSpec::TYPE_TIMESTAMP;

        return [
            'correlationId' => $this->getCorrelationId(),
            'responseCode' => $this->responseCode,
            'offsetType' => $this->offsetType,
            'offset' => $hasValue ? $this->offset : null,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::CONSUMER_UPDATE_RESPONSE->value;
    }
}
