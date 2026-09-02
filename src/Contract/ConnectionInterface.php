<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

interface ConnectionInterface
{
    /** @param array<string, string> $arguments */
    public function createStream(string $name, array $arguments = []): void;

    public function deleteStream(string $name): void;

    /**
     * @param string[] $partitions
     * @param string[] $bindingKeys
     * @param array<string, string> $arguments
     */
    public function createSuperStream(
        string $name,
        array $partitions = [],
        array $bindingKeys = [],
        array $arguments = []
    ): void;

    public function deleteSuperStream(string $name): void;

    /** @return string[] */
    public function route(string $routingKey, string $superStream): array;

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
        int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS,
    ): ProducerInterface;

    /**
     * @param array<int, string> $filterValues Stream filtering values, sent as
     *                            `filter.0`, `filter.1`, ... properties (broker-side,
     *                            chunk-granular filtering — see Consumer's docblock).
     */
    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        array $filterValues = [],
        bool $matchUnfiltered = false,
        bool $singleActiveConsumer = false,
        ?string $superStream = null,
    ): ConsumerInterface;

    public function readLoop(?int $maxFrames = null, ?float $timeout = null): void;

    public function storeOffset(string $reference, string $stream, int $offset): void;
}
