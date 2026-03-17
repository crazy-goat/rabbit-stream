<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class StoreOffsetRequestV1 implements ToStreamBufferInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private string $reference,
        private string $stream,
        private int $offset
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion()
            ->addString($this->reference)
            ->addString($this->stream)
            ->addUInt64($this->offset);
    }

    static public function getKey(): int
    {
        return KeyEnum::STORE_OFFSET->value;
    }
}
