<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class StoreOffsetRequestV1 implements ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private string $reference,
        private string $stream,
        private int $offset
    ) {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion()
            ->addString($this->reference)
            ->addString($this->stream)
            ->addUInt64($this->offset);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'stream' => $this->stream,
            'offset' => $this->offset,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::STORE_OFFSET->value;
    }
}
