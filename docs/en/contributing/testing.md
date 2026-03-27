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

E2E tests require RabbitMQ running via Docker:

```bash
# Start RabbitMQ first
docker compose up -d

# Run E2E tests
./run-e2e.sh
# OR
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

1. **RabbitMQ running** via Docker:
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

The `run-e2e.sh` script automates E2E test execution:

```bash
#!/bin/bash

# 1. Start RabbitMQ
docker compose up -d

# 2. Wait for RabbitMQ to be healthy
echo "Waiting for RabbitMQ..."
sleep 5

# 3. Run E2E tests
./vendor/bin/phpunit --testsuite e2e

# 4. Stop RabbitMQ
docker compose down
```

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

RabbitMQ isn't running:
```bash
docker compose up -d
sleep 5  # Wait for startup
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
