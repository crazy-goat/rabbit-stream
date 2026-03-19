<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;

class TuneResponseV1 implements KeyVersionInterface, ToStreamBufferInterface, FromArrayInterface
{
    public function __construct(private int $frameMax = 0, private int $heartbeat = 0)
    {
    }

    static public function getVersion(): int
    {
        return 1;
    }

    static public function getKey(): int
    {
        return KeyEnum::TUNE_RESPONSE->value;
    }

    public static function fromArray(array $data): static
    {
        return new self($data['frameMax'], $data['heartbeat']);
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16(self::getKey())
            ->addUInt16(self::getVersion())
            ->addUInt32($this->frameMax)
            ->addUInt32($this->heartbeat);
    }
}