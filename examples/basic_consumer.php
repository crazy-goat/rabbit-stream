<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Basic Consumer Example
 *
 * Demonstrates:
 * - Connection creation
 * - Consumer creation with offset specification
 * - Reading messages in a loop
 * - Processing messages
 * - Proper cleanup with try/finally
 */
class BasicConsumerExample
{
    private Connection $connection;
    private int $messageCount = 0;

    public function run(): void
    {
        echo "=== Basic Consumer Example ===\n\n";

        // Step 1: Create connection
        $this->createConnection();

        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();

        // Step 3: Create consumer
        $consumer = $this->createConsumer();

        // Step 4: Consume messages
        $this->consumeMessages($consumer);

        // Step 5: Cleanup
        $this->cleanup($consumer);

        echo "\n=== Example Complete ===\n";
        echo "Messages consumed: {$this->messageCount}\n";
    }

    private function createConnection(): void
    {
        echo "Step 1: Creating connection...\n";

        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

        $this->connection = Connection::create(
            host: $host,
            port: $port,
            user: 'guest',
            password: 'guest',
        );

        echo "  ✓ Connected to {$host}:{$port}\n\n";
    }

    private function createStream(): void
    {
        echo "Step 2: Creating stream 'example-stream'...\n";

        try {
            $this->connection->createStream('example-stream', [
                'max-length-bytes' => '1000000000',
            ]);
            echo "  ✓ Stream created\n\n";
        } catch (\Exception $e) {
            // Stream may already exist, which is fine
            echo "  ℹ Stream may already exist: {$e->getMessage()}\n\n";
        }
    }

    private function createConsumer(): \CrazyGoat\RabbitStream\Client\Consumer
    {
        echo "Step 3: Creating consumer...\n";

        // Create consumer starting from the first message
        $consumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            initialCredit: 10
        );

        echo "  ✓ Consumer created\n";
        echo "  ℹ Starting from offset: first\n\n";

        return $consumer;
    }

    private function consumeMessages(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 4: Consuming messages (max 10)...\n";

        $maxMessages = 10;

        try {
            while ($this->messageCount < $maxMessages) {
                // Read messages with 5-second timeout
                $messages = $consumer->read(timeout: 5.0);

                if (empty($messages)) {
                    echo "  ℹ No more messages, stopping\n";
                    break;
                }

                foreach ($messages as $message) {
                    $this->messageCount++;

                    echo "  ✓ [{$message->getOffset()}] ";

                    // Display message body (truncated if too long)
                    $body = $message->getBody();
                    $bodyStr = is_string($body) ? $body : json_encode($body);
                    if (strlen($bodyStr) > 50) {
                        $bodyStr = substr($bodyStr, 0, 50) . '...';
                    }
                    echo "{$bodyStr}\n";

                    if ($this->messageCount >= $maxMessages) {
                        echo "  ℹ Reached message limit ({$maxMessages})\n";
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            echo "  ✗ Error: {$e->getMessage()}\n";
        }

        echo "\n";
    }

    private function cleanup(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 5: Cleaning up...\n";

        $consumer->close();
        $this->connection->close();

        echo "  ✓ Consumer and connection closed\n";
    }
}

// Run the example
$example = new BasicConsumerExample();
$example->run();
