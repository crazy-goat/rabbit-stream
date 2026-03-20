<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;

/** @phpstan-consistent-constructor */
class StreamMetadata implements FromStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    /** @param array<int, int> $replicasReferences */
    public function __construct(
        private readonly string $streamName,
        private readonly int $responseCode,
        private readonly int $leaderReference,
        private readonly array $replicasReferences
    ) {
    }

    public function getStreamName(): string
    {
        return $this->streamName;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    public function getLeaderReference(): int
    {
        return $this->leaderReference;
    }

    /** @return array<int, int> */
    public function getReplicasReferences(): array
    {
        return $this->replicasReferences;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        $streamName = $buffer->getString();
        $responseCode = $buffer->getUint16();
        $leaderReference = $buffer->getUint16();

        $replicasCount = $buffer->getUint32();
        $replicasReferences = [];
        for ($i = 0; $i < $replicasCount; $i++) {
            $replicasReferences[] = $buffer->getUint16();
        }

        return new self($streamName, $responseCode, $leaderReference, $replicasReferences);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stream' => $this->streamName,
            'responseCode' => $this->responseCode,
            'leaderReference' => $this->leaderReference,
            'replicasReferences' => $this->replicasReferences,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['stream'],
            $data['responseCode'],
            $data['leaderReference'],
            $data['replicasReferences']
        );
    }
}
