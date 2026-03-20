<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

include __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

echo "Connecting to RabbitMQ Stream at $host:$port...\n";

try {
    $connection = Connection::create(
        host: $host,
        port: $port,
        user: 'guest',
        password: 'guest',
        vhost: '/'
    );

    echo "Connected successfully.\n";

    $producer = $connection->createProducer(
        stream: 'test-stream',
        onConfirm: function (ConfirmationStatus $status): void {
            if ($status->isConfirmed()) {
                echo "Message confirmed! ID: {$status->getPublishingId()}\n";
            } else {
                echo "Message failed! ID: {$status->getPublishingId()}, Error: {$status->getErrorCode()}\n";
            }
        }
    );

    echo "Sending message...\n";
    $producer->send("Hello, RabbitStream!");

    echo "Waiting for confirmation...\n";
    $connection->readLoop(maxFrames: 1);

    echo "Closing...\n";
    $producer->close();
    $connection->close();

    echo "Done.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
