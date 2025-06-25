<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\StreamBufferInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\V1Trait;
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