# ResolveOffsetSpec Command Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the ResolveOffsetSpec command (0x001f) that resolves an OffsetSpec to a concrete offset value.

**Architecture:** Create Request and Response classes following existing patterns, register in KeyEnum and ResponseBuilder. The command takes a stream name and OffsetSpec, returns a resolved uint64 offset.

**Tech Stack:** PHP 8.1+, native sockets, PHPUnit for tests

---

## Context

The RabbitMQ Stream protocol includes a `ResolveOffsetSpec` command (key 0x001f) that allows clients to resolve an `OffsetSpec` (like FIRST, LAST, NEXT, OFFSET, TIMESTAMP, INTERVAL) to an actual numeric offset for a given stream. This is useful when you need to know the concrete offset before subscribing or querying.

The `OffsetSpec` VO already exists in `src/VO/OffsetSpec.php` and is used by `SubscribeRequestV1`.

**Protocol structure:**
- Request (0x001f): key (uint16) + version (uint16) + correlationId (uint32) + stream (string) + offsetSpec (binary)
- Response (0x801f): key (uint16) + version (uint16) + correlationId (uint32) + responseCode (uint16) + offset (uint64)

---

### Task 1: Write unit test for ResolveOffsetSpecRequestV1 serialization

**Files:**
- Create: `tests/Request/ResolveOffsetSpecRequestV1Test.php`
- Modify: none
- Test: `./vendor/bin/phpunit tests/Request/ResolveOffsetSpecRequestV1Test.php`

**Step 1: Write the failing test**

```php
<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use PHPUnit\Framework\TestCase;

class ResolveOffsetSpecRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new ResolveOffsetSpecRequestV1(
            'my-stream',
            \CrazyGoat\RabbitStream\VO\OffsetSpec::first()
        );
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001f)                  // key
            . pack('n', 1)                             // version
            . pack('N', 42)                            // correlationId
            . pack('n', strlen('my-stream'))          // stream length
            . 'my-stream'                              // stream
            . pack('n', 0x0001);                       // offsetSpec type (FIRST = 0x0001)

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithOffsetValue(): void
    {
        $request = new ResolveOffsetSpecRequestV1(
            'my-stream',
            \CrazyGoat\RabbitStream\VO\OffsetSpec::offset(12345)
        );
        $request->withCorrelationId(99);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001f)
            . pack('n', 1)
            . pack('N', 99)
            . pack('n', strlen('my-stream'))
            . 'my-stream'
            . pack('n', 0x0004)                        // type OFFSET = 0x0004
            . pack('J', 12345);                        // value uint64

        $this->assertSame($expected, $bytes);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Request/ResolveOffsetSpecRequestV1Test.php --testdox`
Expected: Class not found error

**Step 3: Write minimal implementation**

Create `src/Request/ResolveOffsetSpecRequestV1.php`:

```php
<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class ResolveOffsetSpecRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private string $stream,
        private OffsetSpec $offsetSpec
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addString($this->stream)
            ->addRaw($this->offsetSpec->toStreamBuffer()->getContents());
    }

    static public function getKey(): int
    {
        return KeyEnum::RESOLVE_OFFSET_SPEC->value;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Request/ResolveOffsetSpecRequestV1Test.php --testdox`
Expected: Tests pass

**Step 5: Commit**

```bash
git add src/Request/ResolveOffsetSpecRequestV1.php tests/Request/ResolveOffsetSpecRequestV1Test.php
git commit -m "feat: add ResolveOffsetSpecRequestV1 with tests"
```

---

### Task 2: Write unit test for ResolveOffsetSpecResponseV1 deserialization

**Files:**
- Create: `tests/Response/ResolveOffsetSpecResponseV1Test.php`
- Modify: none
- Test: `./vendor/bin/phpunit tests/Response/ResolveOffsetSpecResponseV1Test.php`

**Step 1: Write the failing test**

```php
<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use PHPUnit\Framework\TestCase;

class ResolveOffsetSpecResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x801f)    // key
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('J', 123456);    // offset (uint64 big-endian)

        $response = ResolveOffsetSpecResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(ResolveOffsetSpecResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());
        $this->assertSame(123456, $response->getOffset());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x801f)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // Stream does not exist

        $this->expectException(\Exception::class);
        ResolveOffsetSpecResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Response/ResolveOffsetSpecResponseV1Test.php --testdox`
Expected: Class not found error

**Step 3: Write minimal implementation**

Create `src/Response/ResolveOffsetSpecResponseV1.php`:

```php
<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class ResolveOffsetSpecResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    private int $offset = 0;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());
        $offset = $buffer->getUint64();

        $object = new self();
        $object->withCorrelationId($correlationId);
        $object->offset = $offset;
        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE->value;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Response/ResolveOffsetSpecResponseV1Test.php --testdox`
Expected: Tests pass

**Step 5: Commit**

```bash
git add src/Response/ResolveOffsetSpecResponseV1.php tests/Response/ResolveOffsetSpecResponseV1Test.php
git commit -m "feat: add ResolveOffsetSpecResponseV1 with tests"
```

---

### Task 3: Add enum cases to KeyEnum

**Files:**
- Modify: `src/Enum/KeyEnum.php`
- Test: `./vendor/bin/phpunit` (all tests should still pass)

**Step 1: Add the two new cases**

Add after line 36 (after `DELETE_SUPER_STREAM = 0x001e;`):

```php
    case RESOLVE_OFFSET_SPEC = 0x001f;
```

Add after line 41 (after `DELETE_SUPER_STREAM_RESPONSE = 0x801e;`):

```php
    case RESOLVE_OFFSET_SPEC_RESPONSE = 0x801f;
```

**Step 2: Run all tests to ensure no regressions**

Run: `./vendor/bin/phpunit --testdox`
Expected: All existing tests pass

**Step 3: Commit**

```bash
git add src/Enum/KeyEnum.php
git commit -m "feat: add RESOLVE_OFFSET_SPEC enum cases"
```

---

### Task 4: Register in ResponseBuilder

**Files:**
- Modify: `src/ResponseBuilder.php`
- Test: `./vendor/bin/phpunit` (all tests should still pass)

**Step 1: Add import at top**

Add after existing Response imports (around line 35):

```php
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
```

**Step 2: Add match case in getV1()**

Add before the `default => throw` line in `getV1()`:

```php
            KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE => ResolveOffsetSpecResponseV1::fromStreamBuffer($responseBuffer),
```

**Step 3: Run all tests**

Run: `./vendor/bin/phpunit --testdox`
Expected: All tests pass

**Step 4: Commit**

```bash
git add src/ResponseBuilder.php
git commit -m "feat: register ResolveOffsetSpecResponse in ResponseBuilder"
```

---

### Task 5: Write E2E test (optional, requires RabbitMQ)

**Files:**
- Create: `tests/E2E/ResolveOffsetSpecE2ETest.php`
- Modify: none
- Test: `./run-e2e.sh` or `./vendor/bin/phpunit tests/E2E/ResolveOffsetSpecE2ETest.php`

**Step 1: Write the E2E test**

```php
<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Connection\StreamConnection;
use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ResolveOffsetSpecE2ETest extends TestCase
{
    private static StreamConnection $connection;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = getenv('RABBITMQ_PORT') ?: 5552;

        self::$connection = new StreamConnection($host, $port);
        self::$connection->open();
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection->close();
    }

    public function testResolveFirstOffset(): void
    {
        $stream = 'test-resolve-stream';

        // Create stream first
        self::$connection->sendMessage(new \CrazyGoat\RabbitStream\Request\CreateRequestV1($stream));

        $request = new ResolveOffsetSpecRequestV1(
            $stream,
            OffsetSpec::first()
        );
        $request->withCorrelationId(1);

        self::$connection->sendMessage($request);
        $response = self::$connection->readMessage();

        $this->assertInstanceOf(\CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1::class, $response);
        $this->assertSame(1, $response->getCorrelationId());
        $this->assertIsInt($response->getOffset());
        $this->assertGreaterThanOrEqual(0, $response->getOffset());
    }

    public function testResolveLastOffset(): void
    {
        $stream = 'test-resolve-last-stream';

        // Create stream and publish some messages
        self::$connection->sendMessage(new \CrazyGoat\RabbitStream\Request\CreateRequestV1($stream));
        $publisherId = 1;
        self::$connection->sendMessage(new \CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1($publisherId, $stream));
        for ($i = 0; $i < 5; $i++) {
            self::$connection->sendMessage(new \CrazyGoat\RabbitStream\Request\PublishRequestV1(
                $publisherId,
                $stream,
                "msg-$i",
                null
            ));
        }

        $request = new ResolveOffsetSpecRequestV1(
            $stream,
            OffsetSpec::last()
        );
        $request->withCorrelationId(2);

        self::$connection->sendMessage($request);
        $response = self::$connection->readMessage();

        $this->assertInstanceOf(\CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1::class, $response);
        $this->assertGreaterThan(0, $response->getOffset());
    }
}
```

**Step 2: Run E2E test (if RabbitMQ available)**

Run: `./vendor/bin/phpunit tests/E2E/ResolveOffsetSpecE2ETest.php --testdox`
Expected: Test passes against real RabbitMQ

**Step 3: Commit (optional if E2E works)**

```bash
git add tests/E2E/ResolveOffsetSpecE2ETest.php
git commit -m "test(e2e): add ResolveOffsetSpec E2E tests"
```

---

## Final Verification

After all tasks complete:

1. Run full test suite: `./vendor/bin/phpunit --testdox`
2. Check code style consistency (follow existing patterns)
3. Ensure no unused imports
4. Verify all tests pass

---

## Notes

- Follow existing code style: `static public function getKey()`, trait usage, etc.
- The `OffsetSpec::toStreamBuffer()` already produces the correct binary format (type + optional value)
- No custom exceptions yet — use `\Exception`
- The command is synchronous (request/response), not server-push
