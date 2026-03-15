<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class ConsumerUpdateReplyV1 implements ToStreamBufferInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private int $correlationId,
        private int $responseCode,
        private int $offsetType,
        private int $offset,
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16(self::getKey())
            ->addUInt16(self::getVersion())
            ->addUInt32($this->correlationId)
            ->addUInt16($this->responseCode)
            ->addUInt16($this->offsetType)
            ->addUInt64($this->offset);
    }

    static public function getKey(): int
    {
        return KeyEnum::CONSUMER_UPDATE_RESPONSE->value;
    }
}
