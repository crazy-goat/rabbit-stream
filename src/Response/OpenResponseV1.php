<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\KeyValue;

class OpenResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface, FromArrayInterface
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

    public static function fromArray(array $data): static
    {
        $properties = array_map(
            fn(array $p) => new KeyValue($p['key'], $p['value']),
            $data['connectionProperties']
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

        $object = new self(...$buffer->getObjectArray(KeyValue::class));
        $object->withCorrelationId($correlationId);

        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::OPEN_RESPONSE->value;
    }
}