<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\StreamBufferInterface;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;
use CrazyGoat\StreamyCarrot\Trait\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;
use CrazyGoat\StreamyCarrot\VO\KeyValue;

class PeerPropertiesToStreamBufferV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
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
        return self::getKeYVersion($this->getCorrelationId())
            ->addArray(...$this->keyValues);
    }

    static public function getKey(): int
    {
        return KeyEnum::PEER_PROPERTIES->value;
    }
}