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
class OpenResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
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

    /** @return KeyValue[] */
    public function getConnectionProperties(): array
    {
        return $this->connectionProperties;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $propsData = TypeCast::toArray($data['connectionProperties'] ?? []);
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

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        $object = new static(...$buffer->getObjectArray(KeyValue::class));
        $object->withCorrelationId($correlationId);

        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::OPEN_RESPONSE->value;
    }
}
