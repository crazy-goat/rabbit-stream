# Iteration 9: Examples, Documentation, and Deprecation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create new examples using the `Connection`/`Producer`/`Consumer` API, update README, and mark old classes as deprecated. Zero BC.

**Why:** Users need working examples of the new API. Old examples use the legacy `StreamClient` API or are broken (`store_offset.php`).

---

## New Examples

### Task 9.1: `examples/producer.php`

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

require_once __DIR__ . '/../vendor/autoload.php';

$connection = Connection::create(
    host: 'localhost',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

$connection->createStream('my-stream', [
    'max-length-bytes' => '1000000000',
]);

$producer = $connection->createProducer('my-stream',
    name: 'my-producer',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$status->getPublishingId()}\n";
        } else {
            echo "Failed: #{$status->getPublishingId()} code={$status->getErrorCode()}\n";
        }
    },
);

for ($i = 0; $i < 10_000; $i++) {
    $producer->send("hello_world_{$i}");
}

$producer->waitForConfirms(timeout: 5);
$producer->close();

echo "Done. Published 10000 messages.\n";

$connection->close();
```

### Task 9.2: `examples/consumer.php`

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

$connection = Connection::create(
    host: 'localhost',
    port: 5552,
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
```

### Task 9.3: `examples/consumer_auto_commit.php`

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

$connection = Connection::create(
    host: 'localhost',
    port: 5552,
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
```

### Task 9.4: `examples/stream_management.php`

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

$connection = Connection::create(
    host: 'localhost',
    port: 5552,
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
```

---

## Documentation Updates

### Task 9.5: Update README.md

Add a "Quick Start" section showing the new API:

```markdown
## Quick Start

### Publishing

\```php
$connection = Connection::create(host: 'localhost', port: 5552);

$producer = $connection->createProducer('my-stream', name: 'my-producer');
$producer->send('hello world');
$producer->waitForConfirms(timeout: 5);
$producer->close();

$connection->close();
\```

### Consuming

\```php
$connection = Connection::create(host: 'localhost', port: 5552);

$consumer = $connection->createConsumer('my-stream', offset: OffsetSpec::first());
while ($messages = $consumer->read(timeout: 5)) {
    foreach ($messages as $msg) {
        echo $msg->getBody() . "\n";
    }
}
$consumer->close();

$connection->close();
\```
```

### Task 9.6: Update CHANGELOG.md

Add entries under `[Unreleased]`:
- Added: `Connection` class — high-level entry point with stream management
- Added: New `Producer` with `sendBatch()`, `waitForConfirms()`, `querySequence()`
- Added: `Consumer` with pull-based `read()`/`readOne()`, auto-commit, offset management
- Added: `OsirisChunkParser` — parses delivery chunks into individual messages
- Added: `AmqpDecoder` / `AmqpMessageDecoder` — decodes AMQP 1.0 messages
- Added: `Message` value object with body, properties, application-properties
- Added: `BinarySerializerInterface` — swappable serialization backend
- Added: `toArray()` on all Request classes, `fromArray()` on all Response classes
- Deprecated: `StreamClient` — use `Connection::create()` instead
- Deprecated: `ProducerConfig` — use `Connection::createProducer()` parameters instead

---

## Deprecation

### Task 9.7: Mark deprecated classes

Add `@deprecated` PHPDoc to:
- `StreamClient` — `@deprecated Use Connection::create() instead`
- `StreamClientConfig` — `@deprecated Use Connection::create() parameters instead`
- `ProducerConfig` — `@deprecated Use Connection::createProducer() parameters instead`

Do NOT remove any classes. They continue to work.

### Task 9.8: Move old examples

Rename existing examples:
- `examples/simple_publisher.php` → `examples/legacy/simple_publisher.php`
- `examples/store_offset.php` → `examples/legacy/store_offset.php`
- `examples/old_test.php` → `examples/legacy/old_test.php`

---

## File Structure After This Iteration

```
examples/
├── producer.php
├── consumer.php
├── consumer_auto_commit.php
├── stream_management.php
├── legacy/
│   ├── simple_publisher.php
│   ├── store_offset.php
│   └── old_test.php
```
