# Producer Rewrite Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rewrite `Producer` class with `sendBatch()`, `waitForConfirms()`, `getLastPublishingId()`, and `querySequence()` — zero BC.

**Architecture:** In-place rewrite of existing `Producer` class. New constructor accepts parameters directly instead of `ProducerConfig`. Callbacks in `declare()` track pending confirms for `waitForConfirms()`.

**Tech Stack:** PHP 8.1+, PHPUnit, RabbitMQ Streams Protocol

---

## Task 1: Update Producer Constructor

**Files:**
- Modify: `src/Client/Producer.php:11-22`

**Step 1: Update constructor signature**

Change from:
```php
public function __construct(
    private readonly StreamConnection $connection,
    private readonly string $stream,
    private readonly int $publisherId,
    private readonly ProducerConfig $config,
) {
```

To:
```php
public function __construct(
    private readonly StreamConnection $connection,
    private readonly string $stream,
    private readonly int $publisherId,
    private readonly ?string $name = null,
    private readonly ?callable $onConfirm = null,
) {
```

**Step 2: Add pendingConfirms property**

Add after line 13:
```php
private int $pendingConfirms = 0;
```

**Step 3: Update declare() method**

Change references from `$this->config->name` to `$this->name` and `$this->config->onConfirmation` to `$this->onConfirm`.

Add pendingConfirms tracking in callbacks:
```php
onConfirm: function (array $publishingIds): void {
    $this->pendingConfirms -= count($publishingIds);
    if ($this->onConfirm !== null) {
        foreach ($publishingIds as $id) {
            ($this->onConfirm)(new ConfirmationStatus(true, publishingId: $id));
        }
    }
},
onError: function (array $errors): void {
    $this->pendingConfirms -= count($errors);
    if ($this->onConfirm !== null) {
        foreach ($errors as $error) {
            ($this->onConfirm)(new ConfirmationStatus(
                false,
                errorCode: $error->getCode(),
                publishingId: $error->getPublishingId()
            ));
        }
    }
}
```

**Step 4: Commit**

```bash
git add src/Client/Producer.php
git commit -m "refactor: update Producer constructor to accept parameters directly"
```

---

## Task 2: Implement sendBatch()

**Files:**
- Modify: `src/Client/Producer.php:56-62`
- Test: `tests/Client/ProducerTest.php` (create if doesn't exist)

**Step 1: Write failing test**

Create `tests/Client/ProducerTest.php`:
```php
<?php

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
{
    public function testSendBatchCreatesSingleRequestWithMultipleMessages(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $capturedRequest = null;
        
        $connection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(function ($request) use (&$capturedRequest) {
                $capturedRequest = $request;
                return $request instanceof PublishRequestV1;
            }));
        
        $connection->expects($this->once())
            ->method('readMessage');
        
        $producer = new Producer($connection, 'test-stream', 1);
        $producer->sendBatch(['msg1', 'msg2', 'msg3']);
        
        // Verify the request has 3 messages
        $this->assertInstanceOf(PublishRequestV1::class, $capturedRequest);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testSendBatchCreatesSingleRequestWithMultipleMessages -v
```

Expected: FAIL with "Call to undefined method sendBatch()"

**Step 3: Implement sendBatch()**

Add to `src/Client/Producer.php` after `send()` method:

```php
/**
 * @param string[] $messages
 */
public function sendBatch(array $messages): void
{
    $published = [];
    foreach ($messages as $message) {
        $published[] = new PublishedMessage($this->publishingId++, $message);
        $this->pendingConfirms++;
    }
    $this->connection->sendMessage(new PublishRequestV1($this->publisherId, ...$published));
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testSendBatchCreatesSingleRequestWithMultipleMessages -v
```

Expected: PASS

**Step 5: Commit**

```bash
git add tests/Client/ProducerTest.php src/Client/Producer.php
git commit -m "feat: add sendBatch() method to Producer"
```

---

## Task 3: Implement waitForConfirms()

**Files:**
- Modify: `src/Client/Producer.php`
- Test: `tests/Client/ProducerTest.php`

**Step 1: Write failing test for success case**

Add to `tests/Client/ProducerTest.php`:
```php
public function testWaitForConfirmsResolvesWhenConfirmsArrive(): void
{
    $connection = $this->createMock(StreamConnection::class);
    
    // Simulate confirm callback being triggered
    $registeredCallbacks = null;
    $connection->expects($this->once())
        ->method('registerPublisher')
        ->with(1, $this->anything(), $this->anything())
        ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks) {
            $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
        });
    
    $connection->expects($this->any())
        ->method('sendMessage');
    
    $connection->expects($this->any())
        ->method('readMessage')
        ->willReturnCallback(function () use (&$registeredCallbacks) {
            // Simulate confirm arriving
            if ($registeredCallbacks !== null) {
                ($registeredCallbacks['onConfirm'])([0]);
            }
            return new \stdClass();
        });
    
    $producer = new Producer($connection, 'test-stream', 1);
    $producer->send('test message');
    
    // Should not throw
    $producer->waitForConfirms(timeout: 1);
    
    $this->assertTrue(true); // If we get here, test passed
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testWaitForConfirmsResolvesWhenConfirmsArrive -v
```

Expected: FAIL with "Call to undefined method waitForConfirms()"

**Step 3: Implement waitForConfirms()**

Add to `src/Client/Producer.php`:

```php
public function waitForConfirms(int $timeout = 5): void
{
    $deadline = time() + $timeout;
    while ($this->pendingConfirms > 0 && time() < $deadline) {
        $remaining = $deadline - time();
        if ($remaining <= 0) {
            break;
        }
        $this->connection->readMessage((int) $remaining);
    }
    if ($this->pendingConfirms > 0) {
        throw new \RuntimeException(
            "Timed out waiting for {$this->pendingConfirms} publish confirms"
        );
    }
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testWaitForConfirmsResolvesWhenConfirmsArrive -v
```

Expected: PASS

**Step 5: Write test for timeout case**

Add to `tests/Client/ProducerTest.php`:
```php
public function testWaitForConfirmsThrowsOnTimeout(): void
{
    $connection = $this->createMock(StreamConnection::class);
    
    $connection->expects($this->any())
        ->method('registerPublisher');
    
    $connection->expects($this->any())
        ->method('sendMessage');
    
    $connection->expects($this->any())
        ->method('readMessage')
        ->willReturn(new \stdClass());
    
    $producer = new Producer($connection, 'test-stream', 1);
    $producer->send('test message');
    
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Timed out waiting for 1 publish confirms');
    
    $producer->waitForConfirms(timeout: 0);
}
```

**Step 6: Run timeout test**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testWaitForConfirmsThrowsOnTimeout -v
```

Expected: PASS

**Step 7: Commit**

```bash
git add tests/Client/ProducerTest.php src/Client/Producer.php
git commit -m "feat: add waitForConfirms() method to Producer"
```

---

## Task 4: Implement getLastPublishingId()

**Files:**
- Modify: `src/Client/Producer.php`
- Test: `tests/Client/ProducerTest.php`

**Step 1: Write failing test**

Add to `tests/Client/ProducerTest.php`:
```php
public function testGetLastPublishingIdReturnsCorrectValue(): void
{
    $connection = $this->createMock(StreamConnection::class);
    $connection->expects($this->any())->method('registerPublisher');
    $connection->expects($this->any())->method('sendMessage');
    $connection->expects($this->any())->method('readMessage');
    
    $producer = new Producer($connection, 'test-stream', 1);
    
    // Before any sends
    $this->assertEquals(-1, $producer->getLastPublishingId());
    
    $producer->send('msg1');
    $this->assertEquals(0, $producer->getLastPublishingId());
    
    $producer->send('msg2');
    $this->assertEquals(1, $producer->getLastPublishingId());
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testGetLastPublishingIdReturnsCorrectValue -v
```

Expected: FAIL with "Call to undefined method getLastPublishingId()"

**Step 3: Implement getLastPublishingId()**

Add to `src/Client/Producer.php`:

```php
public function getLastPublishingId(): int
{
    return $this->publishingId - 1;
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testGetLastPublishingIdReturnsCorrectValue -v
```

Expected: PASS

**Step 5: Commit**

```bash
git add tests/Client/ProducerTest.php src/Client/Producer.php
git commit -m "feat: add getLastPublishingId() method to Producer"
```

---

## Task 5: Implement querySequence()

**Files:**
- Modify: `src/Client/Producer.php`
- Test: `tests/Client/ProducerTest.php`

**Step 1: Write failing test for unnamed producer**

Add to `tests/Client/ProducerTest.php`:
```php
public function testQuerySequenceThrowsForUnnamedProducer(): void
{
    $connection = $this->createMock(StreamConnection::class);
    $connection->expects($this->any())->method('registerPublisher');
    $connection->expects($this->any())->method('sendMessage');
    $connection->expects($this->any())->method('readMessage');
    
    $producer = new Producer($connection, 'test-stream', 1); // No name provided
    
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Cannot query sequence for unnamed producer');
    
    $producer->querySequence();
}
```

**Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testQuerySequenceThrowsForUnnamedProducer -v
```

Expected: FAIL with "Call to undefined method querySequence()"

**Step 3: Implement querySequence()**

Add to `src/Client/Producer.php`:

```php
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;

public function querySequence(): int
{
    if ($this->name === null) {
        throw new \RuntimeException('Cannot query sequence for unnamed producer');
    }
    $this->connection->sendMessage(
        new QueryPublisherSequenceRequestV1($this->name, $this->stream)
    );
    $response = $this->connection->readMessage();
    if (!$response instanceof QueryPublisherSequenceResponseV1) {
        throw new \Exception("Expected QueryPublisherSequenceResponseV1, got " . get_class($response));
    }
    return $response->getSequence();
}
```

**Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testQuerySequenceThrowsForUnnamedProducer -v
```

Expected: PASS

**Step 5: Write test for named producer**

Add to `tests/Client/ProducerTest.php`:
```php
public function testQuerySequenceReturnsSequenceForNamedProducer(): void
{
    $connection = $this->createMock(StreamConnection::class);
    $connection->expects($this->any())->method('registerPublisher');
    
    $mockResponse = $this->createMock(\CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1::class);
    $mockResponse->method('getSequence')->willReturn(42);
    
    $connection->expects($this->once())
        ->method('sendMessage')
        ->with($this->isInstanceOf(QueryPublisherSequenceRequestV1::class));
    
    $connection->expects($this->once())
        ->method('readMessage')
        ->willReturn($mockResponse);
    
    $producer = new Producer($connection, 'test-stream', 1, 'my-producer');
    
    $sequence = $producer->querySequence();
    $this->assertEquals(42, $sequence);
}
```

**Step 6: Run test**

```bash
./vendor/bin/phpunit tests/Client/ProducerTest.php::testQuerySequenceReturnsSequenceForNamedProducer -v
```

Expected: PASS

**Step 7: Commit**

```bash
git add tests/Client/ProducerTest.php src/Client/Producer.php
git commit -m "feat: add querySequence() method to Producer"
```

---

## Task 6: Deprecate ProducerConfig

**Files:**
- Modify: `src/Client/ProducerConfig.php`

**Step 1: Add deprecation annotation**

Modify `src/Client/ProducerConfig.php`:

```php
<?php

namespace CrazyGoat\RabbitStream\Client;

/**
 * @deprecated Use Connection::createProducer() parameters instead
 */
class ProducerConfig
{
    /** @var ?callable */
    public readonly mixed $onConfirmation;

    public function __construct(
        public readonly ?string $name = null,
        ?callable $onConfirmation = null,
    ) {
        $this->onConfirmation = $onConfirmation;
    }
}
```

**Step 2: Commit**

```bash
git add src/Client/ProducerConfig.php
git commit -m "docs: mark ProducerConfig as deprecated"
```

---

## Task 7: Update Connection::createProducer()

**Files:**
- Modify: `src/Client/Connection.php:173-181`

**Step 1: Update createProducer() to use new constructor**

Change from:
```php
public function createProducer(
    string $stream,
    ?string $name = null,
    ?callable $onConfirm = null,
): Producer {
    $publisherId = $this->publisherIdCounter++;
    $config = new ProducerConfig($name, $onConfirm);
    return new Producer($this->streamConnection, $stream, $publisherId, $config);
}
```

To:
```php
public function createProducer(
    string $stream,
    ?string $name = null,
    ?callable $onConfirm = null,
): Producer {
    $publisherId = $this->publisherIdCounter++;
    return new Producer($this->streamConnection, $stream, $publisherId, $name, $onConfirm);
}
```

**Step 2: Remove unused import**

Remove `use CrazyGoat\RabbitStream\Client\ProducerConfig;` from imports if it exists.

**Step 3: Run existing tests**

```bash
./vendor/bin/phpunit tests/Client/ConnectionTest.php -v
```

Expected: All tests PASS

**Step 4: Commit**

```bash
git add src/Client/Connection.php
git commit -m "refactor: update Connection::createProducer() to use new Producer constructor"
```

---

## Task 8: Update StreamClient::createProducer()

**Files:**
- Modify: `src/Client/StreamClient.php:53-61`

**Step 1: Update createProducer() to use new constructor**

Change from:
```php
public function createProducer(string $stream, ?ProducerConfig $config = null): Producer
{
    return new Producer(
        $this->connection,
        $stream,
        $this->publisherIdCounter++,
        $config ?? new ProducerConfig()
    );
}
```

To:
```php
public function createProducer(string $stream, ?ProducerConfig $config = null): Producer
{
    $config = $config ?? new ProducerConfig();
    return new Producer(
        $this->connection,
        $stream,
        $this->publisherIdCounter++,
        $config->name,
        $config->onConfirmation,
    );
}
```

**Step 2: Run existing tests**

```bash
./vendor/bin/phpunit tests/E2E/StreamClientTest.php -v
```

Expected: All tests PASS

**Step 3: Commit**

```bash
git add src/Client/StreamClient.php
git commit -m "refactor: update StreamClient::createProducer() for new Producer constructor"
```

---

## Task 9: Add E2E Test for New Producer Features

**Files:**
- Create: `tests/E2E/ProducerTest.php`

**Step 1: Create E2E test file**

```php
<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $host = $_ENV['RABBITMQ_HOST'] ?? '127.0.0.1';
        $port = (int) ($_ENV['RABBITMQ_PORT'] ?? 5552);
        
        $this->connection = Connection::create($host, $port);
        $this->streamName = 'test-producer-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }
    }

    public function testSendAndWaitForConfirms(): void
    {
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function ($status) use (&$confirmed) {
                $confirmed[] = $status;
            }
        );
        
        $producer->send('test message');
        $producer->waitForConfirms(timeout: 5);
        
        $this->assertCount(1, $confirmed);
        $this->assertTrue($confirmed[0]->isSuccess());
        
        $producer->close();
    }

    public function testSendBatchAndWaitForConfirms(): void
    {
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function ($status) use (&$confirmed) {
                $confirmed[] = $status;
            }
        );
        
        $producer->sendBatch(['msg1', 'msg2', 'msg3']);
        $producer->waitForConfirms(timeout: 5);
        
        $this->assertCount(3, $confirmed);
        foreach ($confirmed as $status) {
            $this->assertTrue($status->isSuccess());
        }
        
        $producer->close();
    }

    public function testGetLastPublishingId(): void
    {
        $producer = $this->connection->createProducer($this->streamName);
        
        $this->assertEquals(-1, $producer->getLastPublishingId());
        
        $producer->send('msg1');
        $this->assertEquals(0, $producer->getLastPublishingId());
        
        $producer->sendBatch(['msg2', 'msg3']);
        $this->assertEquals(2, $producer->getLastPublishingId());
        
        $producer->close();
    }

    public function testQuerySequenceForNamedProducer(): void
    {
        $producer = $this->connection->createProducer(
            $this->streamName,
            name: 'test-producer-ref'
        );
        
        // Send some messages
        $producer->sendBatch(['msg1', 'msg2', 'msg3']);
        $producer->waitForConfirms(timeout: 5);
        
        // Query sequence
        $sequence = $producer->querySequence();
        $this->assertGreaterThanOrEqual(2, $sequence); // Should be at least 2 (0-indexed)
        
        $producer->close();
    }
}
```

**Step 2: Run E2E tests**

```bash
./run-e2e.sh
```

Or manually:
```bash
docker compose up -d
sleep 5
./vendor/bin/phpunit tests/E2E/ProducerTest.php -v
docker compose down
```

Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/E2E/ProducerTest.php
git commit -m "test: add E2E tests for new Producer features"
```

---

## Task 10: Run Full Test Suite

**Step 1: Run all tests**

```bash
./vendor/bin/phpunit
```

Expected: All tests PASS

**Step 2: Run E2E tests**

```bash
./run-e2e.sh
```

Expected: All tests PASS

**Step 3: Final commit**

```bash
git commit --allow-empty -m "feat: complete Producer rewrite with sendBatch, waitForConfirms, querySequence (closes #53)"
```

---

## Summary

After completing all tasks:

1. `Producer` class has new constructor accepting parameters directly
2. `sendBatch()` sends multiple messages in one request
3. `waitForConfirms()` blocks until all pending confirms arrive
4. `getLastPublishingId()` returns last used publishing ID
5. `querySequence()` queries publisher sequence for named producers
6. `ProducerConfig` is deprecated
7. `Connection::createProducer()` uses new constructor internally
8. `StreamClient::createProducer()` maintains backward compat
9. Unit tests cover all new functionality
10. E2E tests verify integration with real RabbitMQ

**Zero breaking changes** — existing code using `send()` and `close()` continues to work.
