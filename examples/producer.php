<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

require_once __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
);

$connection->createStream('my-stream', [
    'max-length-bytes' => '1000000000',
]);

$producer = $connection->createProducer(
    'my-stream',
    name: 'my-producer',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$status->getPublishingId()}\n";
        } else {
            echo "Failed: #{$status->getPublishingId()} code={$status->getErrorCode()}\n";
        }
    },
);

for ($i = 0; $i < 10_000; $i++) {
    $producer->send("hello_world_{$i}");
}

$producer->waitForConfirms(timeout: 5);
$producer->close();

echo "Done. Published 10000 messages.\n";

$connection->close();
