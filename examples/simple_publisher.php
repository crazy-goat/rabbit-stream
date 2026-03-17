<?php

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\ProducerConfig;
use CrazyGoat\RabbitStream\Client\StreamClient;
use CrazyGoat\RabbitStream\Client\StreamClientConfig;

include __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

echo "Connecting to RabbitMQ Stream at $host:$port...\n";

try {
    $client = StreamClient::connect(new StreamClientConfig(
        host: $host,
        port: $port,
    ));

    echo "Connected successfully.\n";

    $producer = $client->createProducer('test-stream', new ProducerConfig(
        onConfirmation: function (ConfirmationStatus $status): void {
            if ($status->isConfirmed()) {
                echo "Message confirmed! ID: {$status->getPublishingId()}\n";
            } else {
                echo "Message failed! ID: {$status->getPublishingId()}, Error: {$status->getErrorCode()}\n";
            }
        }
    ));

    echo "Sending message...\n";
    $producer->send("Hello, RabbitStream!");

    echo "Waiting for confirmation...\n";
    $client->readLoop(maxFrames: 1);

    echo "Closing...\n";
    $producer->close();
    $client->close();

    echo "Done.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
