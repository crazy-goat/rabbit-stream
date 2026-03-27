# Requirements

Before installing and using RabbitStream, ensure your system meets the following requirements.

## PHP Requirements

### Minimum Version

- **PHP 8.1 or higher** is required

Verify your PHP version:

```bash
php -v
```

### Required PHP Extensions

The following extensions must be enabled in your PHP installation:

| Extension | Purpose | Check Command |
|-----------|---------|---------------|
| `mbstring` | String encoding and manipulation | `php -m \| grep mbstring` |
| `sockets` | TCP socket communication | `php -m \| grep sockets` |

Check all required extensions:

```bash
php -m | grep -E "mbstring|sockets"
```

Both extensions are typically enabled by default in most PHP installations. If missing, install them:

**Ubuntu/Debian:**
```bash
sudo apt-get install php-mbstring php-sockets
```

**CentOS/RHEL/Fedora:**
```bash
sudo yum install php-mbstring php-sockets
```

**macOS (Homebrew):**
```bash
brew install php
# Extensions are typically included
```

## RabbitMQ Server Requirements

### Version

- **RabbitMQ 3.9 or higher** with the `rabbitmq_stream` plugin enabled

### Enabling the Stream Plugin

The RabbitMQ Stream protocol is not enabled by default. You must enable the `rabbitmq_stream` plugin:

```bash
rabbitmq-plugins enable rabbitmq_stream
```

Or using Docker (see below), the plugin is enabled automatically via the startup command.

### Network Requirements

- **Port 5552** must be open for the RabbitMQ Stream protocol
- The connection is TCP-based and uses binary framing
- Default port can be changed in RabbitMQ configuration if needed

### Default Credentials

RabbitMQ ships with default credentials for development:

- **Username:** `guest`
- **Password:** `guest`
- **Virtual Host:** `/`

For production, create dedicated users with appropriate permissions.

## Docker Quick Start

The easiest way to get RabbitMQ running locally is with Docker:

### Using Docker Compose

The project includes a `docker-compose.yml` file:

```yaml
services:
  rabbitmq:
    image: rabbitmq:4-management
    ports:
      - "5552:5552"    # Stream protocol
      - "5672:5672"    # AMQP protocol
      - "15672:15672"  # Management UI
    environment:
      RABBITMQ_DEFAULT_USER: guest
      RABBITMQ_DEFAULT_PASS: guest
    command: >
      sh -c "rabbitmq-plugins enable rabbitmq_stream && rabbitmq-server"
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "check_port_connectivity"]
      interval: 5s
      timeout: 10s
      retries: 10
      start_period: 20s
```

Start RabbitMQ:

```bash
docker compose up -d
```

Wait for the health check to pass (about 20-30 seconds):

```bash
docker compose ps
```

Access the management UI at http://localhost:15672 (guest/guest).

### Using Docker Run

If you prefer not to use Docker Compose:

```bash
docker run -d \
  --name rabbitmq \
  -p 5552:5552 \
  -p 5672:5672 \
  -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=guest \
  -e RABBITMQ_DEFAULT_PASS=guest \
  rabbitmq:4-management \
  sh -c "rabbitmq-plugins enable rabbitmq_stream && rabbitmq-server"
```

## Memory and Performance Considerations

### Memory Usage

- RabbitMQ Streams store messages on disk, not in memory
- However, consumers may buffer messages in memory
- Large message batches can increase memory usage

### Recommended Settings

For development:
- **Minimum RAM:** 512MB for RabbitMQ container
- **PHP memory_limit:** 128MB or higher

For production:
- **RAM:** 2GB+ depending on throughput
- **Disk:** SSD recommended for stream storage
- **Network:** Low latency connection between PHP and RabbitMQ

## Verifying Your Setup

Run this PHP script to verify all requirements are met:

```php
<?php

// Check PHP version
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    echo "ERROR: PHP 8.1+ required. Current: " . PHP_VERSION . "\n";
    exit(1);
}
echo "PHP Version: " . PHP_VERSION . " OK\n";

// Check extensions
$required = ['mbstring', 'sockets'];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        echo "ERROR: Extension '{$ext}' is not loaded\n";
        exit(1);
    }
    echo "Extension '{$ext}': OK\n";
}

echo "\nAll requirements met!\n";
```

Save as `check-requirements.php` and run:

```bash
php check-requirements.php
```

## Next Steps

Once requirements are verified, proceed to [Installation](./installation.md).
