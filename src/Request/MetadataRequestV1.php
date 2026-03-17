<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class MetadataRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    /**
     * @param string[] $streams
     */
    public function __construct(private array $streams) {}

    public function toStreamBuffer(): WriteBuffer
    {
        $buffer = self::getKeyVersion($this->getCorrelationId())
            ->addInt32(count($this->streams));

        foreach ($this->streams as $stream) {
            $buffer->addString($stream);
        }

        return $buffer;
    }

    static public function getKey(): int
    {
        return KeyEnum::METADATA->value;
    }
}
