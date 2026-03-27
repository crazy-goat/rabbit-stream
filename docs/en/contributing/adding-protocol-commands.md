# Adding Protocol Commands

This guide walks you through implementing a new RabbitMQ Stream protocol command.

## Overview

Every protocol command requires **4 things**:

1. **Request class** — Client → Server
2. **Response class** — Server → Client
3. **KeyEnum registration** — Protocol key mapping
4. **ResponseBuilder registration** — Response dispatch

## Step-by-Step Guide

### Step 1: Create the Request Class

Create a new file in `src/Request/{CommandName}RequestV1.php`:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class ExampleRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(private string $stream) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addString($this->stream);
    }

    public static function getKey(): int
    {
        return KeyEnum::EXAMPLE->value;
    }
}
```

**Key components:**
- Implements `ToStreamBufferInterface` — For serialization
- Implements `CorrelationInterface` — For request/response matching
- Implements `KeyVersionInterface` — For protocol key/version
- Uses traits for shared functionality
- `toStreamBuffer()` — Serializes the request to wire format
- `getKey()` — Returns the protocol key

### Step 2: Create the Response Class

Create a new file in `src/Response/{CommandName}ResponseV1.php`:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class ExampleResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::assertResponseCodeOk($buffer->getUint16());
        
        $object = new self();
        $object->withCorrelationId($correlationId);
        
        // Parse response-specific fields from buffer...
        
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::EXAMPLE_RESPONSE->value;
    }
}
```

**Key components:**
- Implements `FromStreamBufferInterface` — For deserialization
- `fromStreamBuffer()` — Factory method that parses wire format
- `validateKeyVersion()` — Ensures key/version match expected values
- `assertResponseCodeOk()` — Throws on non-OK response codes
- Returns `null` on graceful parse failure

### Step 3: Register in KeyEnum

Add both request and response keys to `src/Enum/KeyEnum.php`:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Enum;

enum KeyEnum: int
{
    // Existing keys...
    case DECLARE_PUBLISHER = 0x0001;
    case PUBLISH = 0x0002;
    
    // Add your new keys
    case EXAMPLE = 0x00xx;           // Request key
    case EXAMPLE_RESPONSE = 0x80xx;  // Response key (request | 0x8000)
    
    // ... more keys
}
```

**Important:** Response keys are always `request_key | 0x8000`.

### Step 4: Register in ResponseBuilder

Add your response to the dispatch logic in `src/ResponseBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Response\ExampleResponseV1;
// ... other imports

class ResponseBuilder
{
    public static function getV1(int $key, ReadBuffer $responseBuffer): ?object
    {
        return match (KeyEnum::tryFrom($key)) {
            // Existing responses...
            KeyEnum::OPEN_RESPONSE => OpenResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_HANDSHAKE_RESPONSE => SaslHandshakeResponseV1::fromStreamBuffer($responseBuffer),
            
            // Add your new response
            KeyEnum::EXAMPLE_RESPONSE => ExampleResponseV1::fromStreamBuffer($responseBuffer),
            
            // ... more responses
            default => null,
        };
    }
}
```

## Testing Your Command

### Unit Test for Request

Create `tests/Request/ExampleRequestV1Test.php`:

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
        $request = new ExampleRequestV1('my-stream');
        $request->withCorrelationId(42);
        
        $buffer = $request->toStreamBuffer();
        $readBuffer = new ReadBuffer($buffer->getData());
        
        $this->assertSame(0x00xx, $readBuffer->getUint16()); // Key
        $this->assertSame(1, $readBuffer->getUint16());      // Version
        $this->assertSame(42, $readBuffer->getUint32());    // Correlation ID
        $this->assertSame('my-stream', $readBuffer->getString());
    }
    
    public function testReturnsCorrectKey(): void
    {
        $this->assertSame(0x00xx, ExampleRequestV1::getKey());
    }
}
```

### Unit Test for Response

Create `tests/Response/ExampleResponseV1Test.php`:

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
        $buffer = new WriteBuffer();
        $buffer->addUint16(0x80xx);                          // Response key
        $buffer->addUint16(1);                              // Version
        $buffer->addUint32(42);                             // Correlation ID
        $buffer->addUint16(ResponseCodeEnum::OK->value);   // Response code
        // Add response-specific fields...
        
        $readBuffer = new ReadBuffer($buffer->getData());
        $response = ExampleResponseV1::fromStreamBuffer($readBuffer);
        
        $this->assertInstanceOf(ExampleResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }
    
    public function testThrowsOnErrorResponse(): void
    {
        $buffer = new WriteBuffer();
        $buffer->addUint16(0x80xx);
        $buffer->addUint16(1);
        $buffer->addUint32(42);
        $buffer->addUint16(ResponseCodeEnum::ACCESS_REFUSED->value);
        
        $readBuffer = new ReadBuffer($buffer->getData());
        
        $this->expectException(\Exception::class);
        ExampleResponseV1::fromStreamBuffer($readBuffer);
    }
}
```

### E2E Test (Optional)

For commands that interact with RabbitMQ, create an E2E test:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\ExampleRequestV1;
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
    
    public function testCanSendExampleCommand(): void
    {
        $request = new ExampleRequestV1('test-stream');
        $this->connection->sendMessage($request);
        
        $response = $this->connection->readMessage();
        
        $this->assertInstanceOf(ExampleResponseV1::class, $response);
    }
}
```

## Branching Strategy

Always implement new features on a dedicated branch:

```bash
# Create feature branch
git checkout -b feature/issue-{number}-{short-description}

# Example:
git checkout -b feature/issue-9-delete-publisher
```

## After Merging

After your PR is merged to `main`:

1. **Close the GitHub issue**:
   ```bash
   gh issue close {issue-number}
   ```

2. **Update `README.md`**:
   - Change `❌` to `✅` in the Protocol Implementation Status table

3. **Update `CHANGELOG.md`**:
   - Add your change to the `[Unreleased]` section

4. **Commit directly to `main`**:
   ```bash
   git checkout main
   git pull
   # Update README and CHANGELOG
   git add README.md CHANGELOG.md
   git commit -m "docs: mark {Command} as implemented in README, close issue #{number}"
   git push
   ```

## Protocol Reference

Full protocol specification: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc

### Frame Structure

```
Frame => Size (uint32) + Payload
Payload => Key (uint16) + Version (uint16) + [CorrelationId (uint32)] + Content
Response key = Request key | 0x8000
```

### Data Types

- **uint16**: 2 bytes, big-endian
- **uint32**: 4 bytes, big-endian
- **String**: int16 length prefix + UTF-8 bytes
- **Bytes**: int32 length prefix (-1 = null)

## Common Patterns

### Commands with Multiple Parameters

```php
public function __construct(
    private string $stream,
    private int $publisherId,
    private ?string $reference = null,
) {}

public function toStreamBuffer(): WriteBuffer
{
    return self::getKeyVersion($this->getCorrelationId())
        ->addString($this->stream)
        ->addUint16($this->publisherId)
        ->addString($this->reference ?? '');
}
```

### Commands with Array Data

```php
public function __construct(private array $items) {}

public function toStreamBuffer(): WriteBuffer
{
    $buffer = self::getKeyVersion($this->getCorrelationId())
        ->addUint32(count($this->items));
    
    foreach ($this->items as $item) {
        $buffer->addString($item);
    }
    
    return $buffer;
}
```

## Troubleshooting

### "Unknown key" Error

Ensure you've registered the response in `ResponseBuilder::getV1()`.

### "Invalid response code" Error

Check that you're using `assertResponseCodeOk()` in your response class.

### Correlation ID Mismatch

Ensure both request and response use the correlation trait correctly.
