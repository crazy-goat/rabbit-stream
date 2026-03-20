<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

class PublishRequestV1 implements ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
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

    public function toArray(): array
    {
        return [
            'publisherId' => $this->publisherId,
            'messages' => array_map(fn(PublishedMessage $m) => $m->toArray(), $this->messages),
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH->value;
    }
}
