<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\KeyValue;

class PeerPropertiesResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface, FromArrayInterface
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

    public static function fromArray(array $data): static
    {
        $properties = array_map(
            fn(array $p) => new KeyValue($p['key'], $p['value']),
            $data['properties']
        );
        $object = new self(...$properties);
        $object->withCorrelationId($data['correlationId']);
        return $object;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        return new self(...$buffer->getObjectArray(KeyValue::class));
    }
}
