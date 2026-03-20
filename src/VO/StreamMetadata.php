<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;

class StreamMetadata implements FromStreamBufferInterface, ToArrayInterface, FromArrayInterface
{
    public function __construct(
        private string $streamName,
        private int $responseCode,
        private int $leaderReference,
        private array $replicasReferences
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

    public function toArray(): array
    {
        return [
            'stream' => $this->streamName,
            'responseCode' => $this->responseCode,
            'leaderReference' => $this->leaderReference,
            'replicasReferences' => $this->replicasReferences,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['stream'], $data['responseCode'], $data['leaderReference'], $data['replicasReferences']);
    }
}
