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
use CrazyGoat\RabbitStream\VO\KeyValue;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class SubscribeRequestV1 implements ToStreamBufferInterface, ToArrayInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    /**
     * @param array<string, string> $properties
     */
    public function __construct(
        private int $subscriptionId,
        private string $stream,
        private OffsetSpec $offsetSpec,
        private int $credit,
        private array $properties = []
    ) {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        $buffer = self::getKeyVersion($this->getCorrelationId())
            ->addUInt8($this->subscriptionId)
            ->addString($this->stream)
            ->addRaw($this->offsetSpec->toStreamBuffer()->getContents())
            ->addUInt16($this->credit);

        if ($this->properties !== []) {
            $keyValues = [];
            foreach ($this->properties as $key => $value) {
                $keyValues[] = new KeyValue($key, $value);
            }
            $buffer->addArray(...$keyValues);
        }

        return $buffer;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subscriptionId' => $this->subscriptionId,
            'stream' => $this->stream,
            'offsetSpec' => $this->offsetSpec->toArray(),
            'credit' => $this->credit,
            'properties' => $this->properties,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::SUBSCRIBE->value;
    }
}
