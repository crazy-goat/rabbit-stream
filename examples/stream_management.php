<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);
$streamName = 'example-management-' . bin2hex(random_bytes(4));

$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
);

// Create a stream
$connection->createStream($streamName, [
    'max-length-bytes' => '500000000',
    'max-age' => '24h',
]);
echo "Stream created.\n";

// Check if it exists
$exists = $connection->streamExists($streamName);
echo "Exists: " . ($exists ? 'yes' : 'no') . "\n";

// Get stats
$stats = $connection->getStreamStats($streamName);
echo "Stats:\n";
foreach ($stats as $key => $value) {
    echo "  {$key}: {$value}\n";
}

// Delete the stream
$connection->deleteStream($streamName);
echo "Stream deleted.\n";

$connection->close();
