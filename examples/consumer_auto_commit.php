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

// Resume from last stored offset, auto-commit every 1000 messages
$consumer = $connection->createConsumer('my-stream',
    offset: OffsetSpec::first(),
    name: 'my-consumer',
    autoCommit: 1000,
);

$running = true;
pcntl_signal(SIGINT, function () use (&$running) {
    $running = false;
});

while ($running) {
    pcntl_signal_dispatch();

    $message = $consumer->readOne(timeout: 5);
    if ($message === null) {
        continue;
    }

    echo "offset={$message->getOffset()} body={$message->getBody()}\n";
}

$consumer->close(); // stores final offset automatically
$connection->close();
