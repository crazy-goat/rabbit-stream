<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

// Create connection
$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);
$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
    vhost: '/'
);

try {
    $streamName = 'example-management-' . bin2hex(random_bytes(4));

    // Create a simple stream
    echo "Creating stream '$streamName'...\n";
    $connection->createStream($streamName);
    echo "Stream created successfully\n";

    // Clean up
    echo "Deleting stream...\n";
    $connection->deleteStream($streamName);
    echo "Stream deleted successfully\n";
} finally {
    $connection->close();
}
