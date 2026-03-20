<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class HeartbeatRequestV1 implements FromStreamBufferInterface, ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public static function getKey(): int
    {
        return KeyEnum::HEARTBEAT->value;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion();
    }

    public function toArray(): array
    {
        return [];
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        return new self();
    }
}
