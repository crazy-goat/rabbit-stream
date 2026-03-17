<?php

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

class StreamMetadata implements FromStreamBufferInterface
{
    public function __construct(
        private string $streamName,
        private int $responseCode,
        private int $leaderReference,
        private array $replicasReferences
    ) {}

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
        $streamName = $buffer->gatString();
        $responseCode = $buffer->getUint16();
        $leaderReference = $buffer->getUint16();

        $replicasCount = $buffer->getUint32();
        $replicasReferences = [];
        for ($i = 0; $i < $replicasCount; $i++) {
            $replicasReferences[] = $buffer->getUint16();
        }

        return new self($streamName, $responseCode, $leaderReference, $replicasReferences);
    }
}
