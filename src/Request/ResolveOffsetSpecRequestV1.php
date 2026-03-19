<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class ResolveOffsetSpecRequestV1 implements ToStreamBufferInterface, ToArrayInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private string $stream,
        private OffsetSpec $offsetSpec
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addString($this->stream)
            ->addRaw($this->offsetSpec->toStreamBuffer()->getContents())
            ->addUInt32(0); // Properties array length (0 = empty array)
    }

    public function toArray(): array
    {
        return [
            'stream' => $this->stream,
            'offsetSpec' => $this->offsetSpec->toArray(),
        ];
    }

    static public function getKey(): int
    {
        return KeyEnum::RESOLVE_OFFSET_SPEC->value;
    }
}
