<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamConsumerInterface;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

/**
 * Consumes from every partition of a super stream through one object.
 *
 * Each partition is a plain {@see Consumer} subscribed the same way
 * {@see Connection::createConsumer()} subscribes any consumer — auto-commit
 * and single-active-consumer activation/deactivation are handled per-partition
 * by that existing Consumer machinery (see the commit that introduced
 * per-subscription ConsumerUpdate dispatch); this class only aggregates
 * reads and delegates offset/activation queries to the right partition.
 *
 * Offset tracking is entirely PER-PARTITION — there is no aggregate,
 * super-stream-wide offset (see {@see SuperStreamConsumerInterface}).
 */
class SuperStreamConsumer implements SuperStreamConsumerInterface
{
    private int $roundRobinIndex = 0;

    /**
     * @param list<string> $partitions
     * @param array<string, ConsumerInterface> $consumers partition stream name => Consumer
     * @param \Closure(float): int $readLoop runs exactly one bounded readLoop() pass
     *                                        on the underlying connection
     */
    public function __construct(
        private readonly array $partitions,
        private readonly array $consumers,
        private readonly \Closure $readLoop,
    ) {
    }

    private function consumerFor(string $partition): ConsumerInterface
    {
        if (!isset($this->consumers[$partition])) {
            throw new InvalidArgumentException("Unknown partition \"{$partition}\"");
        }
        return $this->consumers[$partition];
    }

    private function anyHasUnread(): bool
    {
        foreach ($this->consumers as $consumer) {
            if ($consumer->hasUnread()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Block until some partition has a buffered message or $timeout elapses.
     * Non-Deliver frames (ConsumerUpdate handovers, heartbeats, confirms) are
     * dispatched but do not end the wait, so an empty read() means "nothing
     * within $timeout" — see Consumer::waitForMessages().
     */
    private function waitForMessages(float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$this->anyHasUnread() && $timeout > 0) {
            // 0 dispatched frames = timeout, stop() or disconnect: nothing more to wait for.
            if (($this->readLoop)($timeout) === 0) {
                return;
            }
            $timeout = $deadline - microtime(true);
        }
    }

    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array
    {
        $this->waitForMessages($timeout);

        $messages = [];
        foreach ($this->consumers as $consumer) {
            foreach ($consumer->drain() as $message) {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    public function readOne(float $timeout = 5.0): ?Message
    {
        $this->waitForMessages($timeout);

        $count = count($this->partitions);
        for ($i = 0; $i < $count; $i++) {
            $index = ($this->roundRobinIndex + $i) % $count;
            $partition = $this->partitions[$index];
            $consumer = $this->consumers[$partition];
            if ($consumer->hasUnread()) {
                // Fair rotation: next call starts just past this partition, not
                // always at partition 0 first.
                $this->roundRobinIndex = ($index + 1) % $count;
                // hasUnread() is true, so readOne() pops a buffered message
                // without performing any connection I/O of its own.
                return $consumer->readOne($timeout);
            }
        }

        return null;
    }

    public function storeOffset(string $partition, int $offset): void
    {
        $this->consumerFor($partition)->storeOffset($offset);
    }

    public function queryOffset(string $partition): int
    {
        return $this->consumerFor($partition)->queryOffset();
    }

    /** @return list<string> */
    public function getPartitions(): array
    {
        return $this->partitions;
    }

    /** @return array<string, ConsumerInterface> */
    public function getConsumers(): array
    {
        return $this->consumers;
    }

    public function isActive(string $partition): bool
    {
        return $this->consumerFor($partition)->isActive();
    }

    public function close(): void
    {
        foreach ($this->consumers as $consumer) {
            $consumer->close();
        }
    }
}
