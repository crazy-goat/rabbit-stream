<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
);

// Create a stream
$connection->createStream('example-stream', [
    'max-length-bytes' => '500000000',
    'max-age' => '24h',
]);
echo "Stream created.\n";

// Check if it exists
$exists = $connection->streamExists('example-stream');
echo "Exists: " . ($exists ? 'yes' : 'no') . "\n";

// Get stats
$stats = $connection->getStreamStats('example-stream');
echo "Stats:\n";
foreach ($stats as $key => $value) {
    echo "  {$key}: {$value}\n";
}

// Delete the stream
$connection->deleteStream('example-stream');
echo "Stream deleted.\n";

$connection->close();
