# PSR Logging

> Integrating with PSR-3 compatible loggers for debugging and monitoring

## Overview

The RabbitStream library integrates with PSR-3 (LoggerInterface) compatible loggers. This allows you to use any PSR-3 compliant logging library (Monolog, log4php, etc.) to capture debug information, connection events, and protocol frame details.

## PSR-3 LoggerInterface Integration

### StreamConnection Constructor

The `StreamConnection` accepts a PSR-3 logger in its constructor:

```php
use CrazyGoat\RabbitStream\StreamConnection;
use Psr\Log\LoggerInterface;

public function __construct(
    private readonly string $host = '127.0.0.1',
    private readonly int $port = 5552,
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly BinarySerializerInterface $serializer = new PhpBinarySerializer(),
)
```

### Default Behavior

By default, `StreamConnection` uses `NullLogger` — a PSR-3 compliant logger that discards all messages:

```php
use CrazyGoat\RabbitStream\StreamConnection;

// No logging (default)
$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
);
```

## What Gets Logged

### Log Levels and Messages

| Level | When | Example Message |
|-------|------|-----------------|
| DEBUG | Frame sent/received | `Socket -> [hex dump]` |
| DEBUG | Server-initiated close | `Server-initiated close: code=1, reason=...` |
| WARNING | Unexpected frames in readLoop | `readLoop() received unexpected non-server-push frame` |

### Debug Frame Logging

All protocol frames are logged at DEBUG level with hex dumps:

**Outgoing frames:**
```
Socket -> 0000001a000100013039000e6d792d6170706c69636174696f6e
```

**Incoming frames:**
```
Socket <- 0000000c8001000130390001
```

### Connection Events

**Server-initiated close:**
```php
$this->logger->debug(sprintf(
    'Server-initiated close: code=%d, reason=%s',
    $closingCode,
    $closingReason ?? ''
));
```

## Configuring a Logger

### Using Monolog

Monolog is the most popular PSR-3 logger for PHP:

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Create a logger
$logger = new Logger('rabbitstream');

// Add handlers
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$logger->pushHandler(new RotatingFileHandler('logs/rabbitstream.log', 7, Logger::INFO));

// Create connection with logger
$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
    logger: $logger,
);
```

### Log Levels Explained

```php
use Monolog\Logger;

// DEBUG: All protocol frames (very verbose)
$logger->pushHandler(new StreamHandler('logs/debug.log', Logger::DEBUG));

// INFO: Connection events, errors
$logger->pushHandler(new StreamHandler('logs/info.log', Logger::INFO));

// WARNING: Unexpected behavior
$logger->pushHandler(new StreamHandler('logs/warning.log', Logger::WARNING));

// ERROR: Connection failures, protocol errors
$logger->pushHandler(new StreamHandler('logs/error.log', Logger::ERROR));
```

### Production Configuration

For production, limit debug logging:

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

$logger = new Logger('rabbitstream');

// Only log INFO and above to file
$logger->pushHandler(new StreamHandler(
    'logs/rabbitstream.log',
    Logger::INFO
));

// Log ERROR and above to stderr
$logger->pushHandler(new StreamHandler(
    'php://stderr',
    Logger::ERROR
));

// Add context processor
$logger->pushProcessor(new PsrLogMessageProcessor());
```

### Development Configuration

For development, enable full debug logging:

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

$logger = new Logger('rabbitstream');

// Custom formatter for readability
$formatter = new LineFormatter(
    "[%datetime%] %level_name%: %message% %context%\n",
    'Y-m-d H:i:s',
    true,  // Allow inline line breaks
    true   // Ignore empty context/extra
);

$handler = new StreamHandler('php://stdout', Logger::DEBUG);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
    logger: $logger,
);
```

## Debug Logging for Protocol Frames

### Enabling Frame Debugging

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Client\Connection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Enable debug logging
$logger = new Logger('debug');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Create connection
$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    logger: $logger,  // Pass logger to connection
);

// All frames will now be logged
$producer = $connection->createProducer('my-stream');
$producer->send('Hello, World!');
```

### Sample Debug Output

```
[2024-01-15 10:30:45] DEBUG: Socket -> 0000001a000100013039000e6d792d6170706c69636174696f6e
[2024-01-15 10:30:45] DEBUG: Socket <- 0000000c8001000130390001
[2024-01-15 10:30:45] DEBUG: Socket -> 0000001e000200013039000100010000000a00086d792d73747265616d
[2024-01-15 10:30:45] DEBUG: Socket <- 0000000c8002000130390001
```

### Interpreting Hex Dumps

Frame structure in hex:
```
0000001a 0001 0001 3039 000e 6d792d6170706c69636174696f6e
└─size─┘ └key┘ └ver┘ └cid┘ └len┘ └────── "my-application" ──────┘

Size: 0x0000001a = 26 bytes
Key:  0x0001 = OPEN command
Ver:  0x0001 = Version 1
CID:  0x3039 = Correlation ID 12345
Len:  0x000e = 14 bytes
Data: "my-application"
```

## Custom Logger Implementation

### Simple File Logger

```php
<?php

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class SimpleFileLogger implements LoggerInterface
{
    private $handle;
    
    public function __construct(string $path)
    {
        $this->handle = fopen($path, 'a');
    }
    
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? json_encode($context) : '';
        fwrite($this->handle, "[$timestamp] $level: $message $contextStr\n");
    }
    
    public function __destruct()
    {
        fclose($this->handle);
    }
}

// Usage
$logger = new SimpleFileLogger('logs/rabbitstream.log');
$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
    logger: $logger,
);
```

### Filtering Logger

Log only specific message patterns:

```php
<?php

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class FilteringLogger implements LoggerInterface
{
    use LoggerTrait;
    
    private LoggerInterface $inner;
    private array $patterns;
    
    public function __construct(LoggerInterface $inner, array $patterns)
    {
        $this->inner = $inner;
        $this->patterns = $patterns;
    }
    
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        foreach ($this->patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                $this->inner->log($level, $message, $context);
                return;
            }
        }
    }
}

// Only log frames containing "0001" (OPEN command)
$filterLogger = new FilteringLogger($logger, ['Socket ->', 'Socket <-']);
```

## Debugging Connection Issues

### Connection Failure Debugging

```php
<?php

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('debug');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

try {
    $connection = new StreamConnection(
        host: '192.168.1.100',
        port: 5552,
        logger: $logger,
    );
    $connection->connect();
} catch (ConnectionException $e) {
    // Check logs for detailed error information
    echo "Connection failed: " . $e->getMessage() . "\n";
    echo "Check logs for frame-level details\n";
}
```

### Protocol Error Debugging

```php
<?php

use CrazyGoat\RabbitStream\Exception\DeserializationException;

try {
    $response = $connection->readMessage();
} catch (DeserializationException $e) {
    // Log will contain the raw frame that failed to parse
    $logger->error('Failed to deserialize response', [
        'error' => $e->getMessage(),
    ]);
}
```

## Performance Considerations

### Debug Logging Overhead

Frame logging adds overhead:
- **Hex encoding:** ~2x data size (binary → hex string)
- **I/O:** Writing to disk or stdout
- **Memory:** Temporary strings for hex dumps

**Recommendation:** Disable DEBUG logging in production high-throughput scenarios.

### Selective Logging

Log only errors in production:

```php
<?php

// Production: only errors
$logger = new Logger('production');
$logger->pushHandler(new StreamHandler('logs/errors.log', Logger::ERROR));

// Development: full debug
if (getenv('APP_ENV') === 'development') {
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
}
```

### Async Logging

For high-throughput, use async loggers:

```php
<?php

use Monolog\Logger;
use Monolog\Handler\AmqpHandler;
use Monolog\Formatter\JsonFormatter;

// Send logs to RabbitMQ (different connection)
$amqpConnection = /* ... */;
$handler = new AmqpHandler($amqpConnection, 'logs');
$handler->setFormatter(new JsonFormatter());

$logger = new Logger('async');
$logger->pushHandler($handler);
```

## Best Practices

### 1. Use NullLogger by Default

```php
// Good: No logging overhead
$connection = new StreamConnection(host: '127.0.0.1', port: 5552);

// Also good: Explicit NullLogger
use Psr\Log\NullLogger;
$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
    logger: new NullLogger(),
);
```

### 2. Separate Log Files by Level

```php
$logger = new Logger('rabbitstream');
$logger->pushHandler(new StreamHandler('logs/debug.log', Logger::DEBUG));
$logger->pushHandler(new StreamHandler('logs/error.log', Logger::ERROR));
```

### 3. Include Context in Logs

```php
$logger->debug('Frame received', [
    'key' => sprintf('0x%04x', $key),
    'size' => strlen($frame),
    'correlation_id' => $correlationId,
]);
```

### 4. Rotate Log Files

```php
use Monolog\Handler\RotatingFileHandler;

// Keep 7 days of logs
$logger->pushHandler(new RotatingFileHandler(
    'logs/rabbitstream.log',
    7,
    Logger::INFO
));
```

## See Also

- [PSR-3 Logger Interface Specification](https://www.php-fig.org/psr/psr-3/)
- [Monolog Documentation](https://github.com/Seldaek/monolog)
- [Connection Lifecycle](../guide/connection-lifecycle.md)
- [Error Handling](../guide/error-handling.md)
