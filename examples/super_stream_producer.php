<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

require_once __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
);

$superStream = 'my-super-stream';
$partitions = ['my-super-stream-0', 'my-super-stream-1', 'my-super-stream-2'];
$bindingKeys = ['0', '1', '2'];

try {
    $connection->createSuperStream($superStream, $partitions, $bindingKeys);
    echo "Created super stream '{$superStream}' with " . count($partitions) . " partitions.\n";
} catch (ProtocolException $e) {
    if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
        echo "Super stream '{$superStream}' already exists.\n";
    } else {
        throw $e;
    }
}

// Default routing strategy (HashRoutingStrategy): the routing key is hashed
// with MurmurHash3 x86_32 (seed 104729), matching the Java/.NET clients, so
// producers in different languages agree on which partition a key lands on.
$producer = $connection->createSuperStreamProducer($superStream, name: 'super-stream-producer');

$customers = ['customer-1', 'customer-2', 'customer-3', 'customer-4', 'customer-5'];

for ($i = 0; $i < 100; $i++) {
    $customerId = $customers[$i % count($customers)];
    $message = json_encode([
        'order_id' => $i,
        'customer_id' => $customerId,
        'amount' => random_int(10, 1000),
    ]);

    $producer->send($message, routingKey: $customerId);
}

$producer->waitForConfirms(timeout: 5.0);

echo "Done. Published 100 messages across partitions:\n";
foreach ($producer->getPartitions() as $partition) {
    echo "  - {$partition}\n";
}

$producer->close();
$connection->close();
