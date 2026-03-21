<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

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
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addUInt16($this->responseCode)
            ->addUInt16($this->offsetType)
            ->addUInt64($this->offset);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'correlationId' => $this->getCorrelationId(),
            'responseCode' => $this->responseCode,
            'offsetType' => $this->offsetType,
            'offset' => $this->offset,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::CONSUMER_UPDATE_RESPONSE->value;
    }
}
