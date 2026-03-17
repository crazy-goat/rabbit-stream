<?php

use CrazyGoat\RabbitStream\Client\StreamClient;
use CrazyGoat\RabbitStream\Client\StreamClientConfig;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;

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

    // Store an offset for a consumer reference
    // This allows resuming consumption from a known position
    $request = new StoreOffsetRequestV1(
        reference: 'my-consumer-ref',
        stream: 'test-stream',
        offset: 100
    );

    echo "Storing offset...\n";
    $client->sendMessage($request);

    echo "Offset stored successfully.\n";

    $client->close();
    echo "Done.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
