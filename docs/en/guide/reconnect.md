# Reconnect

This guide shows how to recover a producer and a consumer after the connection to the broker is lost. The library does not reconnect by itself. The application opens a new connection and creates the producers and consumers again.

## What Happens on Connection Loss

- A call that uses the lost connection throws `ConnectionException`.
- Each publish that is not confirmed yet fails once. Its `onConfirm` callback gets `Producer::CONNECTION_LOST_ERROR_CODE` (`0x10000`). (Tested: `tests/Client/ProducerTest.php`.)
- The broker keeps the stored offset of a named consumer and the stored sequence of a named producer. A new connection reads them back. The sequence resume is tested in `tests/E2E/NamedProducerResumeTest.php`. The consumer example below resumed from the stored offset after a broker restart in a manual check. It has no automated test.
- A message that was sent before the loss can be stored twice when the broker stored it but the confirm got lost. Make the consumer handle a repeated message. (Not tested.)

`TimeoutException` extends `ConnectionException`. Catch `TimeoutException` first when you handle a timeout in a different way. A timeout does not always mean that the connection is lost.

After a broker restart, a call can fail with `ProtocolException` and the response code `ResponseCodeEnum::STREAM_NOT_AVAILABLE` until the stream is ready. The snippets below retry this code and stop on any other `ProtocolException`. (Not tested.)

## Rules

1. Do not use the old `Connection` again after `ConnectionException`.
2. Call `Connection::create()` to open a new connection.
3. Create each producer and consumer again on the new connection.
4. Use the same `name` for a producer or a consumer, so that the broker keeps its state.
5. Create the stream once, outside the retry loop.

## Reconnect a Producer

A named producer reads the last stored sequence when it starts. It continues from that value. See [Querying Sequence After Reconnect](publishing.md#querying-sequence-after-reconnect).

The `onConfirm` callback gets one `ConfirmationStatus` for each publish. A broker error, such as a `PublishError`, gives a status that is not confirmed. The snippet records that body and does not retry it. Decide in your application how to handle such a body.

```php
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

// Create the stream once, outside the retry loop.
$setup = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');
if (!$setup->streamExists('orders')) {
    $setup->createStream('orders');
}
$setup->close();

$connection = null;
$producer = null;
$rejected = [];
foreach (['order-1', 'order-2'] as $body) {
    $lastStatus = null;
    while (true) {
        try {
            $connection ??= Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');
            $producer ??= $connection->createProducer(
                'orders',
                name: 'order-writer',
                onConfirm: function (ConfirmationStatus $status) use (&$lastStatus): void {
                    $lastStatus = $status;
                },
            );
            $producer->send($body);
            $producer->waitForConfirms(timeout: 5.0);
            if ($lastStatus === null || !$lastStatus->isConfirmed()) {
                $rejected[] = $body;
            }
            break;
        } catch (ConnectionException) {
            // The connection is lost. A timeout is handled here too. A timeout does not always mean a loss.
            // The next attempt opens a new connection.
            $connection = null;
            $producer = null;
            sleep(1);
        } catch (ProtocolException $e) {
            if ($e->getResponseCode() !== ResponseCodeEnum::STREAM_NOT_AVAILABLE) {
                throw $e;
            }
            // The stream is not ready yet. The next attempt creates the producer again.
            $producer = null;
            sleep(1);
        }
    }
}

$connection?->close();
```

The `while` loop has no limit. Add a retry limit for your application.

## Reconnect a Consumer

A named consumer stores its next offset with `storeOffset()`. After a reconnect, `Connection::queryOffset()` returns that offset. Pass it to `OffsetSpec::offset()` to resume. See [Offset Tracking](offset-tracking.md).

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Create the stream once, outside the retry loop.
$setup = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');
if (!$setup->streamExists('orders')) {
    $setup->createStream('orders');
}
$setup->close();

while (true) {
    try {
        $connection = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');
        $stored = $connection->queryOffset('order-reader', 'orders');
        $offset = $stored === null ? OffsetSpec::first() : OffsetSpec::offset($stored);
        $consumer = $connection->createConsumer('orders', $offset, name: 'order-reader');
        while (true) {
            foreach ($consumer->read(timeout: 5.0) as $message) {
                echo $message->getOffset(), "\n";
                $consumer->storeOffset($message->getOffset() + 1);
            }
        }
    } catch (ConnectionException) {
        // The connection is lost. Wait, then open a new connection.
        sleep(1);
    } catch (ProtocolException $e) {
        if ($e->getResponseCode() !== ResponseCodeEnum::STREAM_NOT_AVAILABLE) {
            throw $e;
        }
        // The stream is not ready yet. Wait, then try again.
        sleep(1);
    }
}
```

The loop never ends. It reads the messages of the `orders` stream, and it starts again after a connection loss. The consumer reads from the stored offset, so a message after the last stored offset can arrive again.

## Related Pages

- [Error Handling](error-handling.md) - Exception types and retry choices
- [PHP Runtime Model](php-runtime-model.md) - When to call `close()`
- [Consuming](consuming.md) - Consumer patterns
- [Publishing](publishing.md) - Producer patterns and named producers
