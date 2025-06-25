<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\CommandTrait;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\V1Trait;
use CrazyGoat\StreamyCarrot\VO\KeyValue;

class OpenResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /** @var KeyValue[] */
    private array $connectionProperties;

    public function __construct(KeyValue ...$connectionProperties)
    {
        $this->connectionProperties = $connectionProperties;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        $object = new self(...$buffer->getObjectArray(KeyValue::class));
        $object->withCorrelationId($correlationId);

        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::OPEN_RESPONSE->value;
    }
}