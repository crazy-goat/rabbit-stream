<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;
use CrazyGoat\RabbitStream\VO\KeyValue;

/** @phpstan-consistent-constructor */
class PeerPropertiesResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /** @var array<int, KeyValue> */
    private array $peerProperty;

    public function __construct(KeyValue ...$peerProperty)
    {
        $this->peerProperty = array_values($peerProperty);
    }

    /** @return array<int, KeyValue> */
    public function getPeerProperty(): array
    {
        return $this->peerProperty;
    }

    public static function getKey(): int
    {
        return KeyEnum::PEER_PROPERTIES_RESPONSE->value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $propsData = TypeCast::toArray($data['properties'] ?? []);
        $properties = array_map(
            function (mixed $p): KeyValue {
                $pa = is_array($p) ? $p : [];
                return new KeyValue(
                    TypeCast::toString($pa['key'] ?? ''),
                    TypeCast::toNullableString($pa['value'] ?? null)
                );
            },
            $propsData
        );
        $object = new static(...$properties);
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $buffer->getUint32();

        self::assertResponseCodeOk($buffer->getUint16());

        return new static(...$buffer->getObjectArray(KeyValue::class));
    }
}
