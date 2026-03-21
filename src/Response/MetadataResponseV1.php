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
use CrazyGoat\RabbitStream\VO\Broker;
use CrazyGoat\RabbitStream\VO\StreamMetadata;

/** @phpstan-consistent-constructor */
class MetadataResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * @param Broker[] $brokers
     * @param StreamMetadata[] $streamMetadata
     */
    public function __construct(
        private array $brokers,
        private array $streamMetadata
    ) {
    }

    /**
     * @return Broker[]
     */
    public function getBrokers(): array
    {
        return $this->brokers;
    }

    /**
     * @return StreamMetadata[]
     */
    public function getStreamMetadata(): array
    {
        return $this->streamMetadata;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        // Note: Metadata response has no top-level response code.
        // Response codes are per-stream inside each StreamMetadata entry.

        $brokers = $buffer->getObjectArray(Broker::class);
        $streamMetadata = $buffer->getObjectArray(StreamMetadata::class);

        $object = new static($brokers, $streamMetadata);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $brokersData = TypeCast::toArray($data['brokers'] ?? []);
        $brokers = array_map(
            function (mixed $b): Broker {
                $ba = is_array($b) ? $b : [];
                return new Broker(
                    TypeCast::toInt($ba['reference'] ?? 0),
                    TypeCast::toString($ba['host'] ?? ''),
                    TypeCast::toInt($ba['port'] ?? 0)
                );
            },
            $brokersData
        );
        $streamMetadataData = TypeCast::toArray($data['streamMetadata'] ?? []);
        $streamMetadata = array_map(
            function (mixed $s): StreamMetadata {
                $sa = is_array($s) ? $s : [];
                return new StreamMetadata(
                    TypeCast::toString($sa['stream'] ?? ''),
                    TypeCast::toInt($sa['responseCode'] ?? 0),
                    TypeCast::toInt($sa['leaderReference'] ?? 0),
                    TypeCast::toIntArray($sa['replicasReferences'] ?? [])
                );
            },
            $streamMetadataData
        );
        $object = new static($brokers, $streamMetadata);
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::METADATA_RESPONSE->value;
    }
}
