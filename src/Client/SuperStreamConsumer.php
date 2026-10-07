<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamConsumerInterface;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;

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
     * Create a consumer that aggregates reads across all super-stream partitions.
     *
     * @param list<string> $partitions Partition stream names.
     * @param array<string, ConsumerInterface> $consumers Partition stream name => Consumer.
     * @param \Closure(float): int $readLoop Runs exactly one bounded readLoop() pass
     *                                        on the underlying connection.
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
            // A partition dropped by the broker (MetadataUpdate) re-subscribes
            // here, with back-off while it is being recreated.
            $allSubscribed = true;
            foreach ($this->consumers as $consumer) {
                if ($consumer instanceof Consumer && !$consumer->resubscribeIfLost()) {
                    $allSubscribed = false;
                }
            }
            $slice = $allSubscribed ? $timeout : min($timeout, 0.05);
            // 0 dispatched frames = timeout, stop() or disconnect: nothing more to wait for.
            if (($this->readLoop)($slice) === 0 && $allSubscribed) {
                return;
            }
            $timeout = $deadline - microtime(true);
        }
    }

    /**
     * Return buffered messages across all partitions, waiting up to the timeout if needed.
     *
     * @param float $timeout Seconds to wait for at least one buffered message.
     * @return Message[] Messages from all partitions, oldest first within each partition.
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

    /**
     * Return one buffered message, fairly rotating across partitions.
     *
     * @param float $timeout Seconds to wait for a message before giving up.
     * @return Message|null The next message, or null when none arrives in time.
     */
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

    /**
     * Store the next offset to consume for one partition.
     *
     * @param string $partition Partition stream name.
     * @param int $offset Next offset to consume.
     * @throws InvalidArgumentException If $partition is not a partition of this super stream.
     * @throws ProtocolException If the partition's consumer has no name.
     * @throws ConnectionException If the socket is not connected or the write fails.
     * @throws TimeoutException If the write does not complete within the socket timeout.
     */
    public function storeOffset(string $partition, int $offset): void
    {
        $this->consumerFor($partition)->storeOffset($offset);
    }

    /**
     * Query the offset stored on the broker for one partition.
     *
     * Offset tracking is per-partition: pass the partition stream name.
     * `null` means nothing has been stored yet for this partition — the
     * broker's `NO_OFFSET` (`0x13`) answer, which is normal on a first run
     * rather than an error (#467). Any other non-OK response code still raises
     * a ProtocolException.
     *
     * @param string $partition Partition stream name.
     * @return int|null The stored next offset to consume for this partition,
     *              or null when no offset is stored.
     * @throws InvalidArgumentException If $partition is not a partition of this
     *                            super stream.
     * @throws ProtocolException If this consumer has no name, or the broker
     *                            returns a non-OK response code other than NO_OFFSET.
     * @throws UnexpectedResponseException If the server replies with something other
     *                            than a QueryOffset response.
     * @throws ConnectionException If the socket is not connected or the request fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function queryOffset(string $partition): ?int
    {
        return $this->consumerFor($partition)->queryOffset();
    }

    /**
     * Return the partition stream names this consumer subscribes to.
     *
     * @return list<string> Partition stream names.
     */
    public function getPartitions(): array
    {
        return $this->partitions;
    }

    /**
     * Return the underlying consumers keyed by partition stream name.
     *
     * @return array<string, ConsumerInterface> Partition stream name => consumer.
     */
    public function getConsumers(): array
    {
        return $this->consumers;
    }

    /**
     * Whether one partition's consumer is currently allowed to receive messages.
     *
     * @param string $partition Partition stream name.
     * @return bool True when the broker has that partition's consumer active.
     * @throws InvalidArgumentException If $partition is not a partition of this super stream.
     */
    public function isActive(string $partition): bool
    {
        return $this->consumerFor($partition)->isActive();
    }

    /**
     * Close every underlying partition consumer and release its subscription id.
     *
     * @throws ProtocolException If the broker rejects an Unsubscribe with a non-OK response code.
     * @throws ConnectionException If the socket is not connected or an exchange fails.
     * @throws DeserializationException If an Unsubscribe response frame cannot be deserialized.
     * @throws TimeoutException If an Unsubscribe response does not arrive in time.
     * @throws InvalidArgumentException If an Unsubscribe or auto-commit StoreOffset frame
     *                                  exceeds the negotiated outgoing frame size.
     */
    public function close(): void
    {
        foreach ($this->consumers as $consumer) {
            $consumer->close();
        }
    }
}
