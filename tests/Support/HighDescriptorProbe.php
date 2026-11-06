<?php

declare(strict_types=1);

// Child process for tests/Repro/Issue938Test.php. It must run with a raised open file limit.
// It holds 1100 descriptors open, so the client socket gets a descriptor number of at least
// 1024 (FD_SETSIZE on the tested builds), connects a StreamConnection to a loopback listener
// and writes one frame.
//
// Exit codes: 0 = frame written, 1 = ConnectionException (message on stdout),
// 78 = descriptors could not be opened, 79 = loopback listener could not be created.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\StreamConnection;

$held = [];
for ($i = 0; $i < 1100; $i++) {
    $handle = @fopen('/dev/null', 'r');
    if ($handle === false) {
        exit(78);
    }
    $held[] = $handle;
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    exit(79);
}
$port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

$connection = new StreamConnection('127.0.0.1', $port, socketTimeout: 2.0);
$connection->connect();

try {
    $connection->sendFrame("\x00\x00\x00\x02ab");
} catch (ConnectionException $e) {
    echo $e->getMessage(), "\n";
    exit(1);
}

echo "written\n";
exit(0);
