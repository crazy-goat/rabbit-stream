<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;
use CrazyGoat\RabbitStream\VO\Statistic;

/** @phpstan-consistent-constructor */
class StreamStatsResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * @param Statistic[] $stats
     */
    public function __construct(
        private array $stats
    ) {
    }

    /**
     * @return Statistic[]
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());

        $correlationId = $buffer->getUint32();
        $responseCode = $buffer->getUint16();

        if ($responseCode !== 0x01) {
            $code = ResponseCodeEnum::tryFrom($responseCode);
            throw new ProtocolException(
                'StreamStats request failed with response code: ' . $responseCode,
                responseCode: $code
            );
        }

        $stats = $buffer->getObjectArray(Statistic::class);

        $object = new static($stats);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $statsData = TypeCast::toArray($data['stats'] ?? []);
        $stats = array_map(
            function (mixed $s): Statistic {
                $sa = is_array($s) ? $s : [];
                return new Statistic(TypeCast::toString($sa['key'] ?? ''), TypeCast::toInt($sa['value'] ?? 0));
            },
            $statsData
        );
        $object = new static($stats);
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::STREAM_STATS_RESPONSE->value;
    }
}
