<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use LogicException;

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
$smokeRun = getenv('RABBITMQ_SMOKE') === '1';
$readTimeout = $smokeRun ? 1.0 : 5.0;
pcntl_signal(SIGINT, function () use (&$running): void {
    echo "\nShutting down...\n";
    $running = false;
});

$count = 0;
while ($running) {
    pcntl_signal_dispatch();

    $messages = $consumer->read(timeout: $readTimeout);

    foreach ($messages as $msg) {
        // getStream() names the partition (physical stream) this particular
        // message was delivered from; offset tracking is per-partition, so
        // it must be stored against that same partition name.
        $partition = $msg->getStream();
        if ($partition === null) {
            throw new LogicException('A super stream message should include its partition name.');
        }
        echo "partition={$partition} offset={$msg->getOffset()} body=" . print_r($msg->getBody(), true) . "\n";
        $consumer->storeOffset($partition, $msg->getOffset() + 1);
        $count++;
    }

    if ($smokeRun) {
        break;
    }
}

echo "Consumed {$count} messages.\n";

$consumer->close();
$connection->close();
