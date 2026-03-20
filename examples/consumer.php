<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
);

$consumer = $connection->createConsumer('my-stream',
    offset: OffsetSpec::first(),
    name: 'my-consumer',
);

$running = true;
pcntl_signal(SIGINT, function () use (&$running) {
    echo "\nShutting down...\n";
    $running = false;
});

$count = 0;
while ($running) {
    pcntl_signal_dispatch();

    $messages = $consumer->read(timeout: 5);

    foreach ($messages as $msg) {
        echo "offset={$msg->getOffset()} body={$msg->getBody()}\n";
        $count++;
    }
}

echo "Consumed {$count} messages.\n";

$consumer->storeOffset($msg->getOffset());
$consumer->close();
$connection->close();
