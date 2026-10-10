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
$consumer = $connection->createConsumer(
    'my-stream',
    offset: OffsetSpec::first(),
    name: 'my-consumer',
    autoCommit: 1000,
);

$running = true;
$smokeRun = getenv('RABBITMQ_SMOKE') === '1';
$readTimeout = $smokeRun ? 1.0 : 5.0;
pcntl_signal(SIGINT, function () use (&$running): void {
    $running = false;
});

while ($running) {
    pcntl_signal_dispatch();

    $message = $consumer->readOne(timeout: $readTimeout);
    if (!$message instanceof \CrazyGoat\RabbitStream\Client\Message) {
        if ($smokeRun) {
            break;
        }
        continue;
    }

    echo "offset={$message->getOffset()} body=" . print_r($message->getBody(), true) . "\n";

    if ($smokeRun) {
        break;
    }
}

$consumer->close(); // stores final offset automatically
$connection->close();
