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

/** @phpstan-consistent-constructor */
class RouteResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * @param string[] $streams
     */
    public function __construct(
        private array $streams
    ) {
    }

    /**
     * @return string[]
     */
    public function getStreams(): array
    {
        return $this->streams;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());

        $streams = $buffer->getStringArray();

        $object = new static($streams);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $object = new static($data['streams']);
        $object->withCorrelationId($data['correlationId']);
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::ROUTE_RESPONSE->value;
    }
}
