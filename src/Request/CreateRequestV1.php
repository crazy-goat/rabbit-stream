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
use CrazyGoat\RabbitStream\VO\KeyValue;

class CreateRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    /**
     * @param array<string, string> $arguments
     */
    public function __construct(
        private string $stream,
        private array $arguments = []
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        $buffer = self::getKeyVersion($this->getCorrelationId())
            ->addString($this->stream);

        $keyValues = [];
        foreach ($this->arguments as $key => $value) {
            $keyValues[] = new KeyValue($key, $value);
        }

        $buffer->addArray(...$keyValues);

        return $buffer;
    }

    static public function getKey(): int
    {
        return KeyEnum::CREATE->value;
    }
}
