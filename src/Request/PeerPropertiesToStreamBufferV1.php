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

class PeerPropertiesToStreamBufferV1 implements
    ToStreamBufferInterface,
    ToArrayInterface,
    CorrelationInterface,
    KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    private array $keyValues;

    public function __construct(KeyValue ...$keyValues)
    {
        $this->keyValues = $keyValues;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addArray(...$this->keyValues);
    }

    public function toArray(): array
    {
        return ['properties' => array_map(fn(KeyValue $kv) => $kv->toArray(), $this->keyValues)];
    }

    public static function getKey(): int
    {
        return KeyEnum::PEER_PROPERTIES->value;
    }
}
