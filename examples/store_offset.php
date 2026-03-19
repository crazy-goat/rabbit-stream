<?php

use CrazyGoat\RabbitStream\Client\Connection;

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

    // Ensure stream exists
    if (!$connection->streamExists('test-stream')) {
        echo "Creating stream 'test-stream'...\n";
        $connection->createStream('test-stream');
    }

    // Store an offset for a consumer reference
    // This allows resuming consumption from a known position
    echo "Storing offset...\n";
    $connection->storeOffset(
        reference: 'my-consumer-ref',
        stream: 'test-stream',
        offset: 100
    );

    echo "Offset stored successfully.\n";

    // Query the offset back to verify
    $offset = $connection->queryOffset(
        reference: 'my-consumer-ref',
        stream: 'test-stream'
    );
    echo "Retrieved offset: $offset\n";

    $connection->close();
    echo "Done.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
