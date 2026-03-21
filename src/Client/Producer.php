<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

class Producer
{
    private int $publishingId = 0;
    private int $pendingConfirms = 0;

    private ?\Closure $onConfirm = null;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ?string $name = null,
        ?callable $onConfirm = null,
    ) {
        $this->onConfirm = $onConfirm !== null ? \Closure::fromCallable($onConfirm) : null;
        $this->declare();
        $this->initializePublishingId();
    }

    private function initializePublishingId(): void
    {
        if ($this->name !== null && $this->name !== '') {
            $sequence = $this->querySequence();
            $this->publishingId = $sequence + 1;
        }
    }

    private function declare(): void
    {
        $this->connection->registerPublisher(
            $this->publisherId,
            onConfirm: function (array $publishingIds): void {
                $this->pendingConfirms = max(0, $this->pendingConfirms - count($publishingIds));
                if ($this->onConfirm instanceof \Closure) {
                    foreach ($publishingIds as $id) {
                        ($this->onConfirm)(new ConfirmationStatus(true, publishingId: $id));
                    }
                }
            },
            onError: function (array $errors): void {
                $this->pendingConfirms = max(0, $this->pendingConfirms - count($errors));
                if ($this->onConfirm instanceof \Closure) {
                    foreach ($errors as $error) {
                        ($this->onConfirm)(new ConfirmationStatus(
                            false,
                            errorCode: $error->getCode(),
                            publishingId: $error->getPublishingId()
                        ));
                    }
                }
            }
        );

        $this->connection->sendMessage(new DeclarePublisherRequestV1(
            $this->publisherId,
            $this->name,
            $this->stream
        ));
        $this->connection->readMessage();
    }

    public function send(string $message, ?float $timeout = null): void
    {
        $this->pendingConfirms++;
        $this->connection->sendMessage(new PublishRequestV1(
            $this->publisherId,
            new PublishedMessage($this->publishingId++, $message)
        ), $timeout);
    }

    /**
     * @param string[] $messages
     */
    public function sendBatch(array $messages, ?float $timeout = null): void
    {
        if ($messages === []) {
            return;
        }
        $published = [];
        foreach ($messages as $message) {
            $published[] = new PublishedMessage($this->publishingId++, $message);
            $this->pendingConfirms++;
        }
        $this->connection->sendMessage(new PublishRequestV1($this->publisherId, ...$published), $timeout);
    }

    public function close(): void
    {
        $this->connection->unregisterPublisher($this->publisherId);
        $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
        $this->connection->readMessage();
    }

    public function waitForConfirms(float $timeout = 5.0): void
    {
        if ($this->pendingConfirms === 0) {
            return;
        }

        $deadline = microtime(true) + $timeout;
        while ($this->pendingConfirms > 0) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $this->connection->readLoop(timeout: $remaining);
        }
        if ($this->pendingConfirms > 0) {
            throw new TimeoutException(
                "Timed out waiting for {$this->pendingConfirms} publish confirms"
            );
        }
    }

    public function getLastPublishingId(): ?int
    {
        return $this->publishingId === 0 ? null : $this->publishingId - 1;
    }

    public function querySequence(): int
    {
        if ($this->name === null) {
            throw new InvalidArgumentException('Cannot query sequence for unnamed producer');
        }
        $this->connection->sendMessage(
            new QueryPublisherSequenceRequestV1($this->name, $this->stream)
        );
        $response = $this->connection->readMessage();
        if (!$response instanceof QueryPublisherSequenceResponseV1) {
            throw UnexpectedResponseException::create(QueryPublisherSequenceResponseV1::class, $response);
        }
        return $response->getSequence();
    }
}
