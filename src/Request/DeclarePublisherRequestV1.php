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

class DeclarePublisherRequestV1 implements ToStreamBufferInterface, ToArrayInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private int $publisherId,
        private ?string $publisherReference,
        private string $stream,
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addUInt8($this->publisherId)
            ->addString($this->publisherReference ?? '')
            ->addString($this->stream);
    }

    public function toArray(): array
    {
        return [
            'publisherId' => $this->publisherId,
            'publisherReference' => $this->publisherReference,
            'stream' => $this->stream,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::DECLARE_PUBLISHER->value;
    }
}
