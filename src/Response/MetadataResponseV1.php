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
use CrazyGoat\RabbitStream\VO\Broker;
use CrazyGoat\RabbitStream\VO\StreamMetadata;

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

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();

        $brokers = $buffer->getObjectArray(Broker::class);
        $streamMetadata = $buffer->getObjectArray(StreamMetadata::class);

        $object = new self($brokers, $streamMetadata);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    public static function fromArray(array $data): static
    {
        $brokers = array_map(
            fn(array $b) => new Broker($b['reference'], $b['host'], $b['port']),
            $data['brokers']
        );
        $streamMetadata = array_map(
            fn(array $s) => new StreamMetadata(
                $s['stream'],
                $s['responseCode'],
                $s['leaderReference'],
                $s['replicasReferences']
            ),
            $data['streamMetadata']
        );
        $object = new self($brokers, $streamMetadata);
        $object->withCorrelationId($data['correlationId']);
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::METADATA_RESPONSE->value;
    }
}
