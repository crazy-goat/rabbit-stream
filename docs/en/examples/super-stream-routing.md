# Super Stream Routing Example

This example demonstrates a complete workflow for working with RabbitMQ Super Streams, including creation, routing, publishing, and consuming.

## Overview

This example shows how to:
1. Create a super stream with multiple partitions
2. Route messages to specific partitions based on routing keys
3. Publish messages to the correct partitions
4. Consume messages from all partitions
5. Clean up resources

## Prerequisites

- RabbitMQ 3.11+ with the stream plugin enabled
- PHP 8.1+
- The RabbitStream library installed

## Complete Example

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\OffsetType;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;

/**
 * Super Stream Routing Example
 * 
 * This example demonstrates:
 * - Creating a super stream with 3 partitions
 * - Routing messages based on customer ID
 * - Publishing to the correct partition
 * - Consuming from all partitions
 */
class SuperStreamRoutingExample
{
    private Connection $connection;
    private string $superStreamName;
    private int $partitionCount;
    private array $partitions = [];
    private array $producers = [];
    private int $messagesReceived = 0;
    
    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $superStreamName = 'orders',
        int $partitionCount = 3
    ) {
        $this->superStreamName = $superStreamName;
        $this->partitionCount = $partitionCount;
        
        // Build partition names
        for ($i = 0; $i < $partitionCount; $i++) {
            $this->partitions[] = "{$superStreamName}-{$i}";
        }
        
        // Connect to RabbitMQ
        $this->connection = Connection::create(
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            vhost: '/'
        );
        
        echo "Connected to RabbitMQ Streams at {$host}:{$port}\n";
    }
    
    /**
     * Create the super stream with partitions
     */
    public function createSuperStream(): void
    {
        echo "\n=== Creating Super Stream ===\n";
        echo "Name: {$this->superStreamName}\n";
        echo "Partitions: " . implode(', ', $this->partitions) . "\n";
        
        $bindingKeys = array_map('strval', range(0, $this->partitionCount - 1));
        
        $this->connection->getStreamConnection()->sendMessage(
            new CreateSuperStreamRequestV1(
                $this->superStreamName,
                $this->partitions,
                $bindingKeys,
                ['max-age' => '1h'] // 1 hour retention
            )
        );
        
        $response = $this->connection->getStreamConnection()->readMessage();
        
        if ($response instanceof CreateSuperStreamResponseV1) {
            $code = $response->getResponseCode();
            
            if ($code === ResponseCodeEnum::OK) {
                echo "✓ Super stream created successfully\n";
            } elseif ($code === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
                echo "✓ Super stream already exists (continuing)\n";
            } else {
                throw new \Exception("Failed to create super stream: " . $code->getMessage());
            }
        }
    }
    
    /**
     * Verify partitions exist
     */
    public function verifyPartitions(): void
    {
        echo "\n=== Verifying Partitions ===\n";
        
        $this->connection->getStreamConnection()->sendMessage(
            new PartitionsRequestV1($this->superStreamName)
        );
        
        $response = $this->connection->getStreamConnection()->readMessage();
        
        if ($response instanceof PartitionsResponseV1) {
            $discoveredPartitions = $response->getStreams();
            echo "Discovered " . count($discoveredPartitions) . " partitions:\n";
            foreach ($discoveredPartitions as $partition) {
                echo "  - $partition\n";
            }
            
            // Update our partition list with discovered ones
            $this->partitions = $discoveredPartitions;
        }
    }
    
    /**
     * Test routing for different keys
     */
    public function testRouting(): void
    {
        echo "\n=== Testing Routing ===\n";
        
        $testCustomers = ['alice', 'bob', 'charlie', 'diana', 'eve'];
        
        foreach ($testCustomers as $customer) {
            $this->connection->getStreamConnection()->sendMessage(
                new RouteRequestV1($customer, $this->superStreamName)
            );
            
            $response = $this->connection->getStreamConnection()->readMessage();
            
            if ($response instanceof RouteResponseV1) {
                $targetPartitions = $response->getStreams();
                $partitionIndex = $this->getPartitionIndex($customer);
                echo "  Customer '$customer' (hash: {$partitionIndex}) -> " . 
                     implode(', ', $targetPartitions) . "\n";
            }
        }
    }
    
    /**
     * Create producers for each partition
     */
    public function createProducers(): void
    {
        echo "\n=== Creating Producers ===\n";
        
        foreach ($this->partitions as $partition) {
            $this->producers[$partition] = $this->connection->createProducer($partition);
            echo "  Created producer for $partition\n";
        }
    }
    
    /**
     * Publish messages to appropriate partitions
     */
    public function publishMessages(int $messageCount = 100): void
    {
        echo "\n=== Publishing {$messageCount} Messages ===\n";
        
        $customers = ['alice', 'bob', 'charlie', 'diana', 'eve'];
        $distribution = array_fill_keys($this->partitions, 0);
        
        for ($i = 0; $i < $messageCount; $i++) {
            $customer = $customers[$i % count($customers)];
            $partitionIndex = $this->getPartitionIndex($customer);
            $partitionName = $this->partitions[$partitionIndex];
            
            $message = json_encode([
                'order_id' => $i,
                'customer' => $customer,
                'amount' => rand(10, 1000),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
            
            $this->producers[$partitionName]->send($message);
            $distribution[$partitionName]++;
        }
        
        // Wait for all confirms
        foreach ($this->producers as $producer) {
            $producer->waitForConfirms(timeout: 5.0);
        }
        
        echo "Messages published. Distribution:\n";
        foreach ($distribution as $partition => $count) {
            echo "  $partition: $count messages\n";
        }
    }
    
    /**
     * Subscribe to all partitions
     */
    public function subscribeToPartitions(): void
    {
        echo "\n=== Subscribing to Partitions ===\n";
        
        $subscriptionId = 1;
        foreach ($this->partitions as $partition) {
            $this->connection->getStreamConnection()->sendMessage(
                new SubscribeRequestV1(
                    subscriptionId: $subscriptionId,
                    streamName: $partition,
                    offsetType: OffsetType::FIRST,
                    offsetValue: 0
                )
            );
            
            $this->connection->getStreamConnection()->readMessage();
            echo "  Subscribed to $partition (subscriptionId: $subscriptionId)\n";
            $subscriptionId++;
        }
    }
    
    /**
     * Consume messages from all partitions
     */
    public function consumeMessages(int $maxMessages = 20): void
    {
        echo "\n=== Consuming Messages (max {$maxMessages}) ===\n";
        
        $this->messagesReceived = 0;
        
        // Register deliver callback for subscription 1 (orders-0)
        $this->connection->getStreamConnection()->registerDeliverCallback(
            1,
            function ($message) use ($maxMessages) {
                return $this->handleMessage($message, 'orders-0', $maxMessages);
            }
        );
        
        // Register deliver callback for subscription 2 (orders-1)
        $this->connection->getStreamConnection()->registerDeliverCallback(
            2,
            function ($message) use ($maxMessages) {
                return $this->handleMessage($message, 'orders-1', $maxMessages);
            }
        );
        
        // Register deliver callback for subscription 3 (orders-2)
        $this->connection->getStreamConnection()->registerDeliverCallback(
            3,
            function ($message) use ($maxMessages) {
                return $this->handleMessage($message, 'orders-2', $maxMessages);
            }
        );
        
        // Read messages for up to 10 seconds or until we reach max
        $startTime = time();
        while ($this->messagesReceived < $maxMessages && (time() - $startTime) < 10) {
            $this->connection->getStreamConnection()->readLoop(maxFrames: 1, timeout: 0.1);
        }
        
        echo "\nTotal messages received: {$this->messagesReceived}\n";
    }
    
    /**
     * Handle an incoming message
     */
    private function handleMessage($message, string $partition, int $maxMessages): bool
    {
        $data = json_decode($message->getData(), true);
        
        echo sprintf(
            "  [%s] Order #%d from %s: $%d\n",
            $partition,
            $data['order_id'],
            $data['customer'],
            $data['amount']
        );
        
        $this->messagesReceived++;
        
        // Return false to stop receiving more messages for this subscription
        return $this->messagesReceived < $maxMessages;
    }
    
    /**
     * Calculate partition index for a routing key
     */
    private function getPartitionIndex(string $routingKey): int
    {
        return crc32($routingKey) % $this->partitionCount;
    }
    
    /**
     * Clean up resources
     */
    public function cleanup(): void
    {
        echo "\n=== Cleaning Up ===\n";
        
        // Close all producers
        foreach ($this->producers as $partition => $producer) {
            $producer->close();
            echo "  Closed producer for $partition\n";
        }
        
        // Delete super stream
        $this->connection->getStreamConnection()->sendMessage(
            new DeleteSuperStreamRequestV1($this->superStreamName)
        );
        
        $response = $this->connection->getStreamConnection()->readMessage();
        
        if ($response instanceof DeleteSuperStreamResponseV1) {
            if ($response->getResponseCode() === ResponseCodeEnum::OK) {
                echo "  ✓ Super stream deleted\n";
            }
        }
        
        // Close connection
        $this->connection->close();
        echo "  ✓ Connection closed\n";
    }
    
    /**
     * Run the complete example
     */
    public function run(): void
    {
        try {
            $this->createSuperStream();
            $this->verifyPartitions();
            $this->testRouting();
            $this->createProducers();
            $this->publishMessages(100);
            $this->subscribeToPartitions();
            $this->consumeMessages(20);
            $this->cleanup();
            
            echo "\n✓ Example completed successfully!\n";
        } catch (\Exception $e) {
            echo "\n✗ Error: " . $e->getMessage() . "\n";
            $this->cleanup();
            throw $e;
        }
    }
}

// Run the example
$example = new SuperStreamRoutingExample(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    superStreamName: 'orders',
    partitionCount: 3
);

$example->run();
```

## Running the Example

1. **Start RabbitMQ with the stream plugin:**

```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  -p 5552:5552 \
  rabbitmq:3.11-management-alpine

# Enable the stream plugin
docker exec rabbitmq rabbitmq-plugins enable rabbitmq_stream
```

2. **Install dependencies:**

```bash
composer install
```

3. **Run the example:**

```bash
php examples/super-stream-routing.php
```

## Expected Output

```
Connected to RabbitMQ Streams at 127.0.0.1:5552

=== Creating Super Stream ===
Name: orders
Partitions: orders-0, orders-1, orders-2
✓ Super stream created successfully

=== Verifying Partitions ===
Discovered 3 partitions:
  - orders-0
  - orders-1
  - orders-2

=== Testing Routing ===
  Customer 'alice' (hash: 1) -> orders-1
  Customer 'bob' (hash: 2) -> orders-2
  Customer 'charlie' (hash: 0) -> orders-0
  Customer 'diana' (hash: 1) -> orders-1
  Customer 'eve' (hash: 2) -> orders-2

=== Creating Producers ===
  Created producer for orders-0
  Created producer for orders-1
  Created producer for orders-2

=== Publishing 100 Messages ===
Messages published. Distribution:
  orders-0: 20 messages
  orders-1: 40 messages
  orders-2: 40 messages

=== Subscribing to Partitions ===
  Subscribed to orders-0 (subscriptionId: 1)
  Subscribed to orders-1 (subscriptionId: 2)
  Subscribed to orders-2 (subscriptionId: 3)

=== Consuming Messages (max 20) ===
  [orders-0] Order #2 from charlie: $456
  [orders-0] Order #7 from charlie: $789
  [orders-1] Order #0 from alice: $123
  [orders-1] Order #3 from alice: $234
  [orders-2] Order #1 from bob: $345
  ...

Total messages received: 20

=== Cleaning Up ===
  Closed producer for orders-0
  Closed producer for orders-1
  Closed producer for orders-2
  ✓ Super stream deleted
  ✓ Connection closed

✓ Example completed successfully!
```

## Key Concepts Demonstrated

### 1. Consistent Hashing

The example uses `crc32()` for consistent hashing:

```php
private function getPartitionIndex(string $routingKey): int
{
    return crc32($routingKey) % $this->partitionCount;
}
```

This ensures:
- The same customer always maps to the same partition
- Even distribution across partitions
- No need to query the server for routing on every publish

### 2. Partition-Aware Publishing

Messages are routed to the correct partition based on the routing key:

```php
$customer = 'alice';
$partitionIndex = $this->getPartitionIndex($customer);
$partitionName = $this->partitions[$partitionIndex];

$this->producers[$partitionName]->send($message);
```

### 3. Parallel Consumption

Multiple subscriptions allow parallel consumption:

```php
foreach ($this->partitions as $partition) {
    $this->connection->getStreamConnection()->sendMessage(
        new SubscribeRequestV1(
            subscriptionId: $subscriptionId++,
            streamName: $partition,
            offsetType: OffsetType::FIRST,
            offsetValue: 0
        )
    );
}
```

### 4. Resource Cleanup

Always clean up resources in the correct order:

```php
// 1. Close producers
foreach ($this->producers as $producer) {
    $producer->close();
}

// 2. Delete super stream
$connection->sendMessage(new DeleteSuperStreamRequestV1($superStreamName));

// 3. Close connection
$connection->close();
```

## Variations

### Direct Routing (No Hash)

If you want explicit control over routing:

```php
// Create with specific binding keys
$partitions = ['orders-us', 'orders-eu', 'orders-asia'];
$bindingKeys = ['us', 'eu', 'asia'];

// Route based on region
$region = 'eu';
$connection->sendMessage(new RouteRequestV1($region, 'orders'));
```

### Range-Based Routing

For routing based on value ranges:

```php
// Create with range binding keys
$partitions = ['orders-low', 'orders-mid', 'orders-high'];
$bindingKeys = ['0-1000', '1001-5000', '5001-*'];

// Route based on order value
function getPartitionForValue(float $value): string
{
    if ($value <= 1000) return 'orders-low';
    if ($value <= 5000) return 'orders-mid';
    return 'orders-high';
}
```

### Consumer Groups

For coordinated consumption with single active consumer:

```php
$connection->sendMessage(new SubscribeRequestV1(
    subscriptionId: 1,
    streamName: 'orders-0',
    offsetType: OffsetType::FIRST,
    offsetValue: 0,
    groupName: 'order-processors',  // Same for all consumers
    consumerName: 'consumer-1',    // Unique per instance
));
```

## Troubleshooting

### Issue: "Stream does not exist"

**Cause**: Trying to publish to a partition that doesn't exist.

**Solution**: Verify partitions exist before publishing:

```php
$connection->sendMessage(new PartitionsRequestV1($superStreamName));
$response = $connection->readMessage();
```

### Issue: "Access refused"

**Cause**: Insufficient permissions.

**Solution**: Check user has stream management permissions:

```bash
rabbitmqctl set_permissions -p / guest ".*" ".*" ".*"
```

### Issue: Messages not evenly distributed

**Cause**: Poor hash function or skewed routing keys.

**Solution**: Use consistent hashing and verify distribution:

```php
$distribution = [];
foreach ($customers as $customer) {
    $partition = crc32($customer) % $partitionCount;
    $distribution[$partition] = ($distribution[$partition] ?? 0) + 1;
}
print_r($distribution);
```

## See Also

- [Super Streams Guide](../guide/super-streams.md) - Comprehensive guide
- [Stream Management Guide](../guide/stream-management.md) - Managing streams
- [Publishing Guide](../guide/publishing.md) - Publishing messages
- [Consuming Guide](../guide/consuming.md) - Consuming messages
