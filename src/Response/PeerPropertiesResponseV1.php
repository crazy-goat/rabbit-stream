<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\CorrelationTrait;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\V1Trait;
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
        return CommandCode::PEER_PROPERTIES_RESPONSE->value;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        return new self(...$buffer->getObjectArray(KeyValue::class));
    }
}