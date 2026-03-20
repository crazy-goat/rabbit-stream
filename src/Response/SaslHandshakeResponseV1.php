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

/** @phpstan-consistent-constructor */
class SaslHandshakeResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /** @param array<int, string|null> $mechanisms */
    public function __construct(private array $mechanisms)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $rawMechanisms = is_array($data['mechanisms'] ?? null) ? $data['mechanisms'] : [];
        $mechanisms = array_map(
            fn(mixed $m): ?string => $m === null ? null : TypeCast::toString($m),
            array_values($rawMechanisms)
        );
        $object = new static($mechanisms);
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }

    /** @return array<int, string|null> */
    public function getMechanisms(): array
    {
        return $this->mechanisms;
    }

    public static function getKey(): int
    {
        return KeyEnum::SASL_HANDSHAKE_RESPONSE->value;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        self::isResponseCodeOk($buffer->getUint16());

        $object = new static($buffer->getStringArray());
        $object->withCorrelationId($correlationId);

        return $object;
    }
}
