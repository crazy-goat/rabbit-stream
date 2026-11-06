# PHP Runtime Model

A connection lives as long as its PHP process or request. This guide shows how long a connection lives in three contexts, and when to call `close()` in each one.

## Facts About the Library

- A `Connection` object owns one TCP or TLS socket. `Connection::create()` opens a new socket.
- The library has no persistent connections. Each PHP request or worker start opens its own socket. (Not tested.)
- `close()` sends the Close exchange to the broker, closes the producers and consumers of the connection, and closes the socket. A second call does nothing.
- When the object is destroyed, `__destruct()` tries to call `close()`. It logs an error and does not throw. See [Destructor Auto-Cleanup](connection-lifecycle.md#destructor-auto-cleanup).
- The client checks inbound data and sends heartbeats only inside a library call. A process that is idle outside the library sends no heartbeat. (Not tested.)

Because of the last point, a long-lived process must call the library at least once per heartbeat interval. A consumer `read()` loop does this. See [Connection Lifecycle](connection-lifecycle.md#server-push-frames) for the heartbeat rules.

## Web Request

A PHP request starts, runs, and ends. Create the connection in the request handler, and close it before the handler returns.

PHP destroys the objects at the end of the request, so the destructor also closes the connection. Do not rely on the destructor. An explicit `close()` in a `finally` block sends the Close exchange and frees the publisher and subscription ids at once.

The library does not reuse a socket across requests. In a worker-mode runtime (RoadRunner, FrankenPHP worker mode or Swoole), the PHP process lives longer than the request. Follow the rules in [Long-Lived Process](#long-lived-process) in such a runtime. (Not tested.)

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');
try {
    if (!$connection->streamExists('orders')) {
        $connection->createStream('orders');
    }
    $producer = $connection->createProducer('orders');
    $producer->send('order created');
    $producer->waitForConfirms(timeout: 5.0);
    $producer->close();
} finally {
    $connection->close();
}
```

The snippet creates the `orders` stream when it does not exist. See [Stream Management](stream-management.md) for stream options.

## CLI Worker

A CLI worker runs in one process for a long time. It opens one connection at start and closes it when it stops.

Use a `finally` block around the loop. The `finally` block runs when the loop ends, also after an exception.

A stop signal with the default PHP handler ends the process at once. Then neither the `finally` block nor the destructor runs. Install a signal handler that ends the loop, so that the `finally` block runs. (Not tested.)

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');
if (!$connection->streamExists('orders')) {
    $connection->createStream('orders');
}
$consumer = $connection->createConsumer('orders', OffsetSpec::first(), name: 'order-worker');
try {
    $messages = $consumer->read(timeout: 5.0);
    foreach ($messages as $message) {
        echo $message->getOffset(), "\n";
        $consumer->storeOffset($message->getOffset() + 1);
    }
} finally {
    $consumer->close();
    $connection->close();
}
```

The snippet reads one batch. A real worker repeats the `read()` call in a loop.

## Long-Lived Process

A long-lived process keeps one connection for many calls. The connection can fail while the process is idle.

- Call the library at least once per heartbeat interval. A `read()` loop does this.
- Set `requestedHeartbeat` in `Connection::create()` when the default of the broker is not right for your process. A `null` value uses the value that the broker proposes.
- When a call throws `ConnectionException`, the connection is lost. Create a new connection. See [Reconnect](reconnect.md).
- Call `close()` when the process stops.

## See Also

- [Connection Lifecycle](connection-lifecycle.md) - Handshake, close and heartbeat details
- [Reconnect](reconnect.md) - Recover producers and consumers after connection loss
- [Connection API reference](../api-reference/connection.md) - Full method reference
