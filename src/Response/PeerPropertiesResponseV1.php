<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;
use CrazyGoat\StreamyCarrot\Trait\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;
use CrazyGoat\StreamyCarrot\VO\KeyValue;

class PeerPropertiesResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    private array $peerProperty;

    public function __construct(KeyValue ...$peerProperty)
    {
        $this->peerProperty = $peerProperty;
    }

    public function getPeerProperty(): array
    {
        return $this->peerProperty;
    }

    public static function getKey(): int
    {
        return KeyEnum::PEER_PROPERTIES_RESPONSE->value;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        return new self(...$buffer->getObjectArray(KeyValue::class));
    }
}