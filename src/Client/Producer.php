<?php

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

class Producer
{
    private int $publishingId = 0;
    private int $pendingConfirms = 0;
    
    /** @var ?callable */
    private readonly mixed $onConfirm;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ?string $name = null,
        ?callable $onConfirm = null,
    ) {
        $this->onConfirm = $onConfirm;
        $this->declare();
    }

    private function declare(): void
    {
        $this->connection->registerPublisher(
            $this->publisherId,
            onConfirm: function (array $publishingIds): void {
                $this->pendingConfirms -= count($publishingIds);
                if ($this->onConfirm !== null) {
                    foreach ($publishingIds as $id) {
                        ($this->onConfirm)(new ConfirmationStatus(true, publishingId: $id));
                    }
                }
            },
            onError: function (array $errors): void {
                $this->pendingConfirms -= count($errors);
                if ($this->onConfirm !== null) {
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

    public function send(string $message): void
    {
        $this->pendingConfirms++;
        $this->connection->sendMessage(new PublishRequestV1(
            $this->publisherId,
            new PublishedMessage($this->publishingId++, $message)
        ));
    }

    /**
     * @param string[] $messages
     */
    public function sendBatch(array $messages): void
    {
        $published = [];
        foreach ($messages as $message) {
            $published[] = new PublishedMessage($this->publishingId++, $message);
            $this->pendingConfirms++;
        }
        $this->connection->sendMessage(new PublishRequestV1($this->publisherId, ...$published));
    }

    public function close(): void
    {
        $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
        $this->connection->readMessage();
    }

    public function waitForConfirms(int $timeout = 5): void
    {
        $deadline = time() + $timeout;
        while ($this->pendingConfirms > 0 && time() < $deadline) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                break;
            }
            $this->connection->readMessage((int) $remaining);
        }
        if ($this->pendingConfirms > 0) {
            throw new \RuntimeException(
                "Timed out waiting for {$this->pendingConfirms} publish confirms"
            );
        }
    }

    public function getLastPublishingId(): int
    {
        return $this->publishingId - 1;
    }
}
