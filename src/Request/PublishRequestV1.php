<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;
use CrazyGoat\StreamyCarrot\VO\PublishedMessage;

class PublishRequestV1 implements ToStreamBufferInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    private array $messages;

    public function __construct(private int $publisherId, PublishedMessage ...$messages)
    {
        $this->messages = $messages;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion()
            ->addUInt8($this->publisherId)
            ->addArray(...$this->messages);
    }

    static public function getKey(): int
    {
        return KeyEnum::PUBLISH->value;
    }
}
