# Testing

This guide covers the test suite structure and how to run and write tests.

## Test Structure

Tests live in the `tests/` directory with PSR-4 autoloading under `CrazyGoat\RabbitStream\Tests\`, mirroring the `src/` structure:

```
tests/
├── Buffer/              # ReadBuffer, WriteBuffer tests
│   ├── ReadBufferTest.php
│   └── WriteBufferTest.php
├── Request/             # Serialization tests for each request class
│   ├── SaslHandshakeRequestV1Test.php
│   ├── OpenRequestV1Test.php
│   └── ...
├── Response/            # Deserialization tests for each response class
│   ├── OpenResponseV1Test.php
│   ├── SaslHandshakeResponseV1Test.php
│   └── ...
└── E2E/                 # Integration tests against real RabbitMQ
    ├── ConnectionTest.php
    ├── PublisherTest.php
    └── ...
```

## Running Tests

### All Tests

```bash
composer test
# OR
./vendor/bin/phpunit
```

### Unit Tests Only

Unit tests don't require RabbitMQ:

```bash
composer test:unit
# OR
./vendor/bin/phpunit --testsuite unit
```

### E2E Tests Only

E2E tests need a running RabbitMQ broker. Run this command from the project root:

```bash
./run-e2e.sh
```

The script starts its own broker and stops it at exit. The broker has a TLS stream listener with mutual TLS for `tests/E2E/TlsConnectionE2ETest.php`. The script generates the test certificates with `bin/gen-e2e-certs.sh` into a gitignored `.e2e-tls.*` directory and deletes them at exit. That test is skipped when you run the suite without the script, because it needs `RABBITMQ_TLS_PORT`, `RABBITMQ_TLS_CERT_DIR` and `RABBITMQ_TLS_PASSPHRASE`. Do not run `docker compose up -d` before `run-e2e.sh`. A broker that you start by hand keeps running after the script ends.

To run only the PHPUnit suite, start a broker and check its health. In a worktree, load the environment first with `set -a && . ./.env.worktree && set +a`. Then run:

```bash
docker compose up -d
docker compose ps   # wait until the status shows (healthy)
./vendor/bin/phpunit --testsuite e2e
```

### Single Test File

```bash
./vendor/bin/phpunit tests/Request/SaslHandshakeRequestV1Test.php
```

### Single Test Method

```bash
./vendor/bin/phpunit --filter testSerializesCorrectly
```

### Verbose Output

```bash
./vendor/bin/phpunit --testdox
```

## Writing Tests

### Request Test Template

Request tests verify that request objects serialize correctly to the wire format:

<!-- docs-lint: ignore -->
```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Request\ExampleRequestV1;
use PHPUnit\Framework\TestCase;

class ExampleRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        // Arrange
        $request = new ExampleRequestV1('my-stream');
        $request->withCorrelationId(42);
        
        // Act
        $buffer = $request->toStreamBuffer();
        
        // Assert
        $readBuffer = new ReadBuffer($buffer->getData());
        
        // Verify key and version
        $this->assertSame(0x00xx, $readBuffer->getUint16()); // Key
        $this->assertSame(1, $readBuffer->getUint16());      // Version
        $this->assertSame(42, $readBuffer->getUint32());    // Correlation ID
        
        // Verify payload
        $this->assertSame('my-stream', $readBuffer->getString());
    }
    
    public function testReturnsCorrectKey(): void
    {
        $this->assertSame(0x00xx, ExampleRequestV1::getKey());
    }
}
```

### Response Test Template

Response tests verify that response objects deserialize correctly from the wire format:

<!-- docs-lint: ignore -->
```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Response\ExampleResponseV1;
use PHPUnit\Framework\TestCase;

class ExampleResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        // Arrange - build response buffer
        $buffer = new WriteBuffer();
        $buffer->addUint16(0x80xx);                          // Response key
        $buffer->addUint16(1);                              // Version
        $buffer->addUint32(42);                             // Correlation ID
        $buffer->addUint16(ResponseCodeEnum::OK->value);   // Response code
        // Add response-specific fields...
        
        $readBuffer = new ReadBuffer($buffer->getData());
        
        // Act
        $response = ExampleResponseV1::fromStreamBuffer($readBuffer);
        
        // Assert
        $this->assertInstanceOf(ExampleResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }
    
    public function testThrowsOnErrorResponse(): void
    {
        // Arrange - build error response
        $buffer = new WriteBuffer();
        $buffer->addUint16(0x80xx);                               // Response key
        $buffer->addUint16(1);                                   // Version
        $buffer->addUint32(42);                                  // Correlation ID
        $buffer->addUint16(ResponseCodeEnum::ACCESS_REFUSED->value); // Error code
        
        $readBuffer = new ReadBuffer($buffer->getData());
        
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ACCESS_REFUSED');
        
        // Act
        ExampleResponseV1::fromStreamBuffer($readBuffer);
    }
}
```

### E2E Test Requirements

E2E tests require:

1. **RabbitMQ running** via Docker. `./run-e2e.sh` starts the broker for you. To start it by hand, run:
   ```bash
   docker compose up -d
   ```

2. **Environment variables** (optional, defaults shown):
   ```bash
   export RABBITMQ_HOST=127.0.0.1
   export RABBITMQ_PORT=5552
   ```

3. **Test stream creation** — Most E2E tests create temporary streams

Example E2E test structure:

<!-- docs-lint: ignore -->
```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class ExampleE2ETest extends TestCase
{
    private ?StreamConnection $connection = null;
    
    protected function setUp(): void
    {
        $this->connection = new StreamConnection(
            host: $_ENV['RABBITMQ_HOST'] ?? '127.0.0.1',
            port: (int) ($_ENV['RABBITMQ_PORT'] ?? 5552),
        );
        $this->connection->connect();
    }
    
    protected function tearDown(): void
    {
        $this->connection?->close();
    }
    
    public function testCanConnect(): void
    {
        $this->assertTrue($this->connection->isConnected());
    }
}
```

## E2E Setup Script

The `run-e2e.sh` script runs the whole E2E flow. Run it from the project root:

```bash
./run-e2e.sh
```

The script runs these steps in order:

1. Load `.env.worktree` when it exists. Pick a free host port for each port variable that is not set.
2. Pull the RabbitMQ image. Try `mirror.gcr.io` first, then `public.ecr.aws`, with three attempts for each.
3. If every pull fails, use a copy of the image that is already in the local Docker cache. If no copy is cached, the script stops with an error.
4. Start the broker with `docker compose up -d`.
5. Wait until the broker is healthy. The wait is bounded, see below.
6. Run the E2E suite with `./vendor/bin/phpunit --testsuite e2e --testdox`.
7. Run the example smoke tests with `bin/run-examples.sh`.
8. Stop the broker with `docker compose down`. This runs on exit, also after a failure.

The wait resolves the container with `docker compose ps -q rabbitmq` (so a
worktree's `COMPOSE_PROJECT_NAME` and remapped ports are honoured) and polls its
health status with `docker inspect -f` — no host `python3` and no dependency on
the JSON shape of `docker compose ps --format json`. It gives up after
`E2E_HEALTH_RETRIES` polls (default `90`) spaced `E2E_HEALTH_INTERVAL` seconds
apart (default `2`), i.e. three minutes; on timeout it prints the last status,
the tail of the broker log and exits non-zero instead of polling forever.

## PHPUnit Configuration

The `phpunit.xml` file defines test suites:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
            <exclude>tests/E2E</exclude>
        </testsuite>
        <testsuite name="e2e">
            <directory>tests/E2E</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

## Test Naming Conventions

- **Test classes**: `{ClassName}Test.php` (e.g., `OpenRequestV1Test.php`)
- **Test methods**: `test{Description}` (e.g., `testSerializesCorrectly`)
- **Data providers**: `{methodName}DataProvider` (e.g., `testSerializesCorrectlyDataProvider`)

## Best Practices

1. **Test one thing per test method** — Don't test multiple behaviors in one test
2. **Use descriptive names** — Test names should describe the behavior being tested
3. **Arrange-Act-Assert** — Structure tests clearly
4. **Use data providers** — For testing multiple similar cases
5. **Clean up in tearDown** — Close connections, delete temporary resources
6. **Don't skip E2E tests** — Use `@group e2e` and run with `--testsuite e2e`

## Troubleshooting

### E2E Tests Fail with "Connection Refused"

RabbitMQ isn't running. Start it and wait until the status shows `(healthy)`:
```bash
docker compose up -d
docker compose ps
```

### Tests Pass Locally but Fail in CI

Check for:
- Environment variable differences
- PHP version mismatches
- Missing PHP extensions

### PHPUnit Cache Issues

Clear the cache:
```bash
rm -rf .phpunit.cache
```
