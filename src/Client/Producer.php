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

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ProducerConfig $config,
    ) {
        $this->declare();
    }

    private function declare(): void
    {
        $this->connection->registerPublisher(
            $this->publisherId,
            onConfirm: function (array $publishingIds): void {
                if ($this->config->onConfirmation) {
                    foreach ($publishingIds as $id) {
                        ($this->config->onConfirmation)(new ConfirmationStatus(true, publishingId: $id));
                    }
                }
            },
            onError: function (array $errors): void {
                if ($this->config->onConfirmation) {
                    foreach ($errors as $error) {
                        ($this->config->onConfirmation)(new ConfirmationStatus(
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
            $this->config->name,
            $this->stream
        ));
        $this->connection->readMessage();
    }

    public function send(string $message): void
    {
        $this->connection->sendMessage(new PublishRequestV1(
            $this->publisherId,
            new PublishedMessage($this->publishingId++, $message)
        ));
    }

    public function close(): void
    {
        $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
        $this->connection->readMessage();
    }
}
