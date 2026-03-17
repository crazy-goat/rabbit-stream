<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\Statistic;

class StreamStatsResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * @param Statistic[] $stats
     */
    public function __construct(
        private array $stats
    ) {}

    /**
     * @return Statistic[]
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();
        $responseCode = $buffer->getUint16();

        if ($responseCode !== 0x01) {
            throw new \Exception('StreamStats request failed with response code: ' . $responseCode);
        }

        $stats = $buffer->getObjectArray(Statistic::class);

        $object = new self($stats);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::STREAM_STATS_RESPONSE->value;
    }
}
