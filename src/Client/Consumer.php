<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class Consumer
{
    private const MAX_UINT16 = 65535;

    /** @var Message[] */
    private array $buffer = [];
    private int $messagesProcessed = 0;
    private int $lastOffset = 0;
    private int $pendingCredits = 0;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $subscriptionId,
        private readonly OffsetSpec $offset,
        private readonly ?string $name = null,
        private readonly int $autoCommit = 0,
        private readonly int $initialCredit = 10,
        private readonly int $maxBufferSize = 1000,
    ) {
        if ($this->maxBufferSize <= 0) {
            throw new \InvalidArgumentException('maxBufferSize must be greater than 0');
        }
        $this->subscribe();
    }

    private function subscribe(): void
    {
        $this->connection->registerSubscriber(
            $this->subscriptionId,
            function ($deliverResponse): void {
                $entries = OsirisChunkParser::parse($deliverResponse->getChunkBytes());
                $messages = AmqpMessageDecoder::decodeAll($entries);
                $this->buffer = array_merge($this->buffer, $messages);

                if (count($this->buffer) < $this->maxBufferSize) {
                    $this->connection->sendMessage(
                        new CreditRequestV1($this->subscriptionId, 1)
                    );
                } elseif ($this->pendingCredits < self::MAX_UINT16) {
                    $this->pendingCredits++;
                }
            },
        );

        $this->connection->sendMessage(
            new SubscribeRequestV1(
                $this->subscriptionId,
                $this->stream,
                $this->offset,
                $this->initialCredit,
            )
        );
        $this->connection->readMessage();
    }

    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array
    {
        if ($this->buffer === []) {
            $this->connection->readLoop(maxFrames: 1, timeout: $timeout);
        }

        $messages = $this->buffer;
        $this->buffer = [];

        if ($messages !== []) {
            $this->sendPendingCredits();
            $lastMsg = end($messages);
            $this->lastOffset = $lastMsg->getOffset();
            $this->messagesProcessed += count($messages);
            $this->maybeAutoCommit();
        }

        return $messages;
    }

    public function readOne(float $timeout = 5.0): ?Message
    {
        if ($this->buffer === []) {
            $this->connection->readLoop(maxFrames: 1, timeout: $timeout);
        }

        if ($this->buffer === []) {
            return null;
        }

        $message = array_shift($this->buffer);
        $this->lastOffset = $message->getOffset();
        $this->messagesProcessed++;
        $this->maybeAutoCommit();
        $this->sendPendingCredits();

        return $message;
    }

    public function storeOffset(int $offset): void
    {
        if ($this->name === null) {
            throw new \RuntimeException('Cannot store offset for unnamed consumer');
        }
        $this->connection->sendMessage(
            new StoreOffsetRequestV1($this->name, $this->stream, $offset)
        );
    }

    public function queryOffset(): int
    {
        if ($this->name === null) {
            throw new \RuntimeException('Cannot query offset for unnamed consumer');
        }
        $this->connection->sendMessage(
            new QueryOffsetRequestV1($this->name, $this->stream)
        );
        $response = $this->connection->readMessage();
        if (!$response instanceof QueryOffsetResponseV1) {
            $type = get_debug_type($response);
            throw new \Exception("Expected QueryOffsetResponseV1, got " . $type);
        }
        return $response->getOffset();
    }

    public function close(): void
    {
        if ($this->autoCommit > 0 && $this->name !== null && $this->messagesProcessed > 0) {
            $this->storeOffset($this->lastOffset);
        }

        $this->connection->unregisterSubscriber($this->subscriptionId);

        $this->connection->sendMessage(
            new UnsubscribeRequestV1($this->subscriptionId)
        );
        $this->connection->readMessage();
    }

    private function maybeAutoCommit(): void
    {
        if ($this->autoCommit <= 0 || $this->name === null) {
            return;
        }
        if ($this->messagesProcessed >= $this->autoCommit) {
            $this->storeOffset($this->lastOffset);
            $this->messagesProcessed = 0;
        }
    }

    private function sendPendingCredits(): void
    {
        if ($this->pendingCredits <= 0) {
            return;
        }

        $availableSlots = $this->maxBufferSize - count($this->buffer);
        if ($availableSlots <= 0) {
            return;
        }

        $creditsToSend = min($this->pendingCredits, $availableSlots, self::MAX_UINT16);
        $this->connection->sendMessage(
            new CreditRequestV1($this->subscriptionId, $creditsToSend)
        );
        $this->pendingCredits -= $creditsToSend;
    }
}
