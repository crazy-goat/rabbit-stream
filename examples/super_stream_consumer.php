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

$superStream = 'my-super-stream';

$consumer = $connection->createSuperStreamConsumer(
    $superStream,
    offset: OffsetSpec::first(),
    name: 'super-stream-consumer',
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
        // getStream() names the partition (physical stream) this particular
        // message was delivered from; offset tracking is per-partition, so
        // it must be stored against that same partition name.
        echo "partition={$msg->getStream()} offset={$msg->getOffset()} body={$msg->getBody()}\n";
        $consumer->storeOffset($msg->getStream(), $msg->getOffset() + 1);
        $count++;
    }
}

echo "Consumed {$count} messages.\n";

$consumer->close();
$connection->close();
