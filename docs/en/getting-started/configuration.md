# Configuration

RabbitStream provides flexible configuration options for connections, logging, serialization, and tuning.

## Connection Configuration

### Connection::create() Parameters

The `Connection::create()` method accepts the following parameters:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use Psr\Log\LoggerInterface;

$connection = Connection::create(
    host: '127.0.0.1',              // RabbitMQ host
    port: 5552,                    // Stream protocol port
    user: 'guest',                 // Username
    password: 'guest',             // Password
    vhost: '/',                    // Virtual host
    serializer: null,              // Custom serializer (optional)
    logger: null,                  // PSR-3 logger (optional)
    requestedFrameMax: null,       // Max frame size (optional)
    requestedHeartbeat: null,        // Heartbeat interval in seconds (optional)
    streamConnection: null,        // Pre-configured StreamConnection (optional)
);
```

### Parameter Reference

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `host` | `string` | `'127.0.0.1'` | RabbitMQ server hostname or IP |
| `port` | `int` | `5552` | Stream protocol port (5552 is default) |
| `user` | `string` | `'guest'` | Username for authentication |
| `password` | `string` | `'guest'` | Password for authentication |
| `vhost` | `string` | `'/'` | Virtual host path |
| `serializer` | `?BinarySerializerInterface` | `null` | Custom binary serializer |
| `logger` | `?LoggerInterface` | `null` | PSR-3 compatible logger |
| `requestedFrameMax` | `?int` | `null` | Maximum frame size in bytes |
| `requestedHeartbeat` | `?int` | `null` | Heartbeat interval in seconds |
| `streamConnection` | `?StreamConnection` | `null` | Pre-configured connection instance |

### Basic Connection Examples

**Local development:**
```php
$connection = Connection::create();
```

**Production with custom host:**
```php
$connection = Connection::create(
    host: 'rabbitmq.example.com',
    port: 5552,
    user: 'app_user',
    password: 'secure_password',
    vhost: '/production',
);
```

**With environment variables:**
```php
$connection = Connection::create(
    host: getenv('RABBITMQ_HOST') ?: '127.0.0.1',
    port: (int)(getenv('RABBITMQ_PORT') ?: 5552),
    user: getenv('RABBITMQ_USER') ?: 'guest',
    password: getenv('RABBITMQ_PASSWORD') ?: 'guest',
);
```

## Custom Logger (PSR-3)

RabbitStream supports any PSR-3 compatible logger for debugging and monitoring.

### Using Monolog

```php
use CrazyGoat\RabbitStream\Client\Connection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create a logger
$logger = new Logger('rabbitstream');
$logger->pushHandler(new StreamHandler('rabbitmq.log', Logger::DEBUG));

// Pass to connection
$connection = Connection::create(
    host: '127.0.0.1',
    logger: $logger,
);
```

### Using NullLogger (Default)

By default, RabbitStream uses `NullLogger` which discards all log messages:

```php
use Psr\Log\NullLogger;

// This is what happens internally when logger is null
$logger = new NullLogger();
```

### Custom Logger Implementation

Implement `Psr\Log\LoggerInterface` for custom logging:

```php
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class CustomLogger implements LoggerInterface
{
    public function log($level, $message, array $context = []): void
    {
        // Your custom logging logic
        error_log("[{$level}] {$message}");
    }
    
    // Implement other required methods...
    public function emergency($message, array $context = []): void {}
    public function alert($message, array $context = []): void {}
    public function critical($message, array $context = []): void {}
    public function error($message, array $context = []): void {}
    public function warning($message, array $context = []): void {}
    public function notice($message, array $context = []): void {}
    public function info($message, array $context = []): void {}
    public function debug($message, array $context = []): void {}
}

$connection = Connection::create(
    logger: new CustomLogger(),
);
```

## Custom Serializer

RabbitStream uses binary serialization for message payloads. You can provide a custom implementation.

### Default Serializer

The default `PhpBinarySerializer` uses PHP's native `serialize()` and `unserialize()`:

```php
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;

$serializer = new PhpBinarySerializer();
```

### Custom Serializer Implementation

Implement `BinarySerializerInterface` for custom serialization:

```php
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;

class JsonSerializer implements BinarySerializerInterface
{
    public function serialize(mixed $data): string
    {
        return json_encode($data);
    }
    
    public function deserialize(string $data): mixed
    {
        return json_decode($data, true);
    }
}

// Use custom serializer
$connection = Connection::create(
    serializer: new JsonSerializer(),
);
```

### BinarySerializerInterface

```php
interface BinarySerializerInterface
{
    public function serialize(mixed $data): string;
    public function deserialize(string $data): mixed;
}
```

## Environment Variables

RabbitStream examples and tests use environment variables for flexible configuration:

### Standard Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `RABBITMQ_HOST` | `127.0.0.1` | RabbitMQ server hostname |
| `RABBITMQ_PORT` | `5552` | Stream protocol port |
| `RABBITMQ_USER` | `guest` | Username |
| `RABBITMQ_PASSWORD` | `guest` | Password |
| `RABBITMQ_VHOST` | `/` | Virtual host |

### Using Environment Variables

```php
$connection = Connection::create(
    host: getenv('RABBITMQ_HOST') ?: '127.0.0.1',
    port: (int)(getenv('RABBITMQ_PORT') ?: 5552),
    user: getenv('RABBITMQ_USER') ?: 'guest',
    password: getenv('RABBITMQ_PASSWORD') ?: 'guest',
    vhost: getenv('RABBITMQ_VHOST') ?: '/',
);
```

### Setting Environment Variables

**Linux/macOS:**
```bash
export RABBITMQ_HOST=rabbitmq.example.com
export RABBITMQ_PORT=5552
export RABBITMQ_USER=app_user
export RABBITMQ_PASSWORD=secret
php your-script.php
```

**Windows (Command Prompt):**
```cmd
set RABBITMQ_HOST=rabbitmq.example.com
set RABBITMQ_PORT=5552
php your-script.php
```

**Windows (PowerShell):**
```powershell
$env:RABBITMQ_HOST = "rabbitmq.example.com"
$env:RABBITMQ_PORT = "5552"
php your-script.php
```

**Docker Compose:**
```yaml
services:
  app:
    environment:
      RABBITMQ_HOST: rabbitmq
      RABBITMQ_PORT: 5552
      RABBITMQ_USER: guest
      RABBITMQ_PASSWORD: guest
```

## Connection Tuning

### Frame Size Limits

Control the maximum frame size for network efficiency:

```php
$connection = Connection::create(
    requestedFrameMax: 65536,  // 64KB frames
);
```

- Smaller frames: Lower memory usage, more network overhead
- Larger frames: Higher throughput, more memory usage
- Default: Negotiated with server (typically 1MB)

### Heartbeat Configuration

Keep connections alive with heartbeats:

```php
$connection = Connection::create(
    requestedHeartbeat: 30,  // Send heartbeat every 30 seconds
);
```

- Prevents connection timeouts during idle periods
- Must be less than server timeout
- Default: Negotiated with server (typically 60 seconds)

### Complete Tuning Example

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Production-ready configuration
$logger = new Logger('rabbitstream');
$logger->pushHandler(new StreamHandler('rabbitmq.log', Logger::INFO));

$connection = Connection::create(
    host: 'rabbitmq.production.internal',
    port: 5552,
    user: 'app_user',
    password: $_ENV['RABBITMQ_PASSWORD'],
    vhost: '/production',
    logger: $logger,
    requestedFrameMax: 131072,     // 128KB frames
    requestedHeartbeat: 30,        // 30 second heartbeat
);
```

## Stream Configuration

When creating streams, you can specify various options:

```php
$connection->createStream('my-stream', [
    'max-length-bytes' => '1000000000',      // 1GB max size
    'max-age' => '24h',                       // Messages expire after 24 hours
    'max-segment-size-bytes' => '500000000', // 500MB segment files
    'initial-cluster-size' => '3',            // Replication factor (cluster mode)
]);
```

### Stream Options Reference

| Option | Type | Description |
|--------|------|-------------|
| `max-length-bytes` | string | Maximum stream size in bytes |
| `max-age` | string | Maximum message age (e.g., '24h', '7d') |
| `max-segment-size-bytes` | string | Size of individual segment files |
| `initial-cluster-size` | string | Number of replicas (cluster mode) |

## Best Practices

### Production Configuration

```php
$connection = Connection::create(
    host: $_ENV['RABBITMQ_HOST'],
    port: (int)$_ENV['RABBITMQ_PORT'],
    user: $_ENV['RABBITMQ_USER'],
    password: $_ENV['RABBITMQ_PASSWORD'],
    vhost: $_ENV['RABBITMQ_VHOST'] ?? '/',
    logger: $productionLogger,     // Always log in production
    requestedHeartbeat: 30,        // Prevent timeouts
);
```

### Development Configuration

```php
$connection = Connection::create(
    host: '127.0.0.1',
    logger: new Logger('dev'),     // Verbose logging
);
```

### Testing Configuration

```php
// Use environment variables for CI/CD flexibility
$connection = Connection::create(
    host: getenv('RABBITMQ_HOST') ?: '127.0.0.1',
    port: (int)(getenv('RABBITMQ_PORT') ?: 5552),
);
```

## Next Steps

- Learn about [Publishing](../guide/publishing.md) patterns and best practices
- Explore [Consuming](../guide/consuming.md) strategies
- Read the [API Reference](../api/index.md) for complete documentation
- Check [Examples](../../examples/) for real-world usage patterns
