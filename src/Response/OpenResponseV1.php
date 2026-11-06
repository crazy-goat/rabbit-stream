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

    /**
     * The host that the broker advertises for later connections (`advertised_host`).
     *
     * Returns null when the property is absent or empty.
     */
    public function getAdvertisedHost(): ?string
    {
        $host = $this->getConnectionPropertyValue('advertised_host');

        return $host === null || $host === '' ? null : $host;
    }

    /**
     * The port that the broker advertises for later connections (`advertised_port`).
     *
     * Returns null when the property is absent, is not a decimal number, or is outside 1..65535.
     */
    public function getAdvertisedPort(): ?int
    {
        $port = $this->getConnectionPropertyValue('advertised_port');

        if ($port === null || preg_match('/^[0-9]{1,5}\z/', $port) !== 1) {
            return null;
        }

        $value = (int)$port;

        return $value >= 1 && $value <= 65535 ? $value : null;
    }

    private function getConnectionPropertyValue(string $key): ?string
    {
        foreach ($this->connectionProperties as $property) {
            if ($property->getKey() === $key) {
                return $property->getValue();
            }
        }

        return null;
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

        self::assertResponseCodeOk($buffer->getUint16());

        $object = new static(...$buffer->getObjectArray(KeyValue::class));
        $object->withCorrelationId($correlationId);

        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::OPEN_RESPONSE->value;
    }
}
