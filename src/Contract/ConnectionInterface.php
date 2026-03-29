<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

interface ConnectionInterface
{
    /** @param array<string, string> $arguments */
    public function createStream(string $name, array $arguments = []): void;

    public function deleteStream(string $name): void;

    public function streamExists(string $name): bool;

    /** @return array<string, int> */
    public function getStreamStats(string $name): array;

    /** @param array<int, string> $streams */
    public function getMetadata(array $streams): MetadataResponseV1;

    public function queryOffset(string $reference, string $stream): int;

    public function close(): void;

    public function createProducer(
        string $stream,
        ?string $name = null,
        ?callable $onConfirm = null,
    ): ProducerInterface;

    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
    ): ConsumerInterface;

    public function readLoop(?int $maxFrames = null, ?float $timeout = null): void;

    public function storeOffset(string $reference, string $stream, int $offset): void;
}
