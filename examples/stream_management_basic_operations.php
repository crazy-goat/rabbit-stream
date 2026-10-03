<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

// Create connection
$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    vhost: '/'
);

try {
    $streamName = 'example-stream';

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
