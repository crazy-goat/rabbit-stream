<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;
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

    /**
     * Resolve a super stream's partition (physical stream) names.
     *
     * @return list<string>
     */
    public function partitions(string $superStream): array;

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
        float $redeclareTimeout = Producer::DEFAULT_REDECLARE_TIMEOUT,
    ): ProducerInterface;

    /**
     * @param array<int, string> $filterValues Stream filtering values, sent as
     *                            `filter.0`, `filter.1`, ... properties (broker-side,
     *                            chunk-granular filtering — see Consumer's docblock).
     * @param int $maxDecodeDepth Maximum AMQP nesting depth accepted when a delivered
     *                            message is decoded — see Consumer's constructor (#450).
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
        int $creditWindowBytes = Consumer::DEFAULT_CREDIT_WINDOW_BYTES,
        int $maxDecodeDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
    ): ConsumerInterface;

    /**
     * Create a producer that publishes to a super stream's partitions,
     * routing each message via $strategy (default: hash routing — see
     * {@see \CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy}).
     */
    public function createSuperStreamProducer(
        string $superStream,
        ?RoutingStrategy $strategy = null,
        ?string $name = null,
        ?callable $onConfirm = null,
        int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS,
        float $redeclareTimeout = Producer::DEFAULT_REDECLARE_TIMEOUT,
    ): SuperStreamProducerInterface;

    /**
     * Create a consumer that subscribes to every partition of a super stream,
     * all sharing the same consumer $name (required for single active
     * consumer to group them server-side).
     */
    public function createSuperStreamConsumer(
        string $superStream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        bool $singleActiveConsumer = false,
        int $creditWindowBytes = Consumer::DEFAULT_CREDIT_WINDOW_BYTES,
        int $maxDecodeDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
    ): SuperStreamConsumerInterface;

    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int;

    public function storeOffset(string $reference, string $stream, int $offset): void;
}
