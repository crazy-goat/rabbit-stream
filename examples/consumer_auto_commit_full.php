<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Consumer Auto-Commit Example
 *
 * Demonstrates:
 * - Creating a named consumer with auto-commit enabled
 * - Automatic offset storage every N messages
 * - Final offset storage on close()
 * - Recovery after restart
 */
class ConsumerAutoCommitExample
{
    private Connection $connection;
    private string $consumerName = 'auto-commit-demo';
    private int $messagesToProcess = 50;

    public function run(): void
    {
        echo "=== Consumer Auto-Commit Example ===\n\n";

        // Step 1: Create connection
        $this->createConnection();

        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();

        // Step 3: Check for existing offset (resume scenario)
        $startOffset = $this->checkExistingOffset();

        // Step 4: Create consumer with auto-commit
        $consumer = $this->createConsumer($startOffset);

        // Step 5: Process messages
        $processed = $this->processMessages($consumer);

        // Step 6: Cleanup (triggers final offset storage)
        $this->cleanup($consumer);

        echo "\n=== Example Complete ===\n";
        echo "Messages processed: {$processed}\n";
        echo "Consumer name: {$this->consumerName}\n";
        echo "\nRun this example again to see resume behavior!\n";
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
            echo "  ℹ Stream may already exist: {$e->getMessage()}\n\n";
        }
    }

    private function checkExistingOffset(): ?int
    {
        echo "Step 3: Checking for existing offset...\n";

        // Create temporary consumer to query offset
        $tempConsumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            name: $this->consumerName
        );

        $lastOffset = $tempConsumer->queryOffset();

        if ($lastOffset === null) {
            echo "  ℹ No stored offset found (first run)\n\n";
        } else {
            echo "  ✓ Found stored offset {$lastOffset}; will resume from this offset\n\n";
        }

        $tempConsumer->close();

        return $lastOffset;
    }

    private function createConsumer(?int $startOffset): \CrazyGoat\RabbitStream\Client\Consumer
    {
        echo "Step 4: Creating consumer with auto-commit...\n";

        // Create consumer with auto-commit every 10 messages
        $consumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: $startOffset === null
                ? OffsetSpec::first()
                : OffsetSpec::offset($startOffset),
            name: $this->consumerName,
            autoCommit: 10,  // Store offset every 10 messages
            initialCredit: 20
        );

        echo "  ✓ Consumer created\n";
        echo "  ℹ Auto-commit interval: 10 messages\n";
        echo "  ℹ Consumer name: {$this->consumerName}\n\n";

        return $consumer;
    }

    private function processMessages(\CrazyGoat\RabbitStream\Client\Consumer $consumer): int
    {
        echo "Step 5: Processing messages (max {$this->messagesToProcess})...\n";

        $processed = 0;
        $lastStoredOffset = null;

        try {
            while ($processed < $this->messagesToProcess) {
                // Read messages with 5-second timeout
                $messages = $consumer->read(timeout: 5.0);

                if (empty($messages)) {
                    echo "  ℹ No more messages, stopping\n";
                    break;
                }

                foreach ($messages as $message) {
                    $processed++;
                    $currentOffset = $message->getOffset();

                    // Simulate message processing
                    $this->simulateProcessing($message);

                    // Auto-commit happens automatically every 10 messages
                    // We can track when it happens by checking the offset
                    if ($processed % 10 === 0) {
                        echo "  ✓ [{$currentOffset}] Processed {$processed} messages ";
                        echo "(auto-commit triggered)\n";
                        $lastStoredOffset = $currentOffset;
                    } else {
                        echo "  ✓ [{$currentOffset}] Processed message {$processed}\n";
                    }

                    if ($processed >= $this->messagesToProcess) {
                        echo "  ℹ Reached message limit ({$this->messagesToProcess})\n";
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            echo "  ✗ Error: {$e->getMessage()}\n";
        }

        if ($lastStoredOffset === null) {
            echo "\n  ℹ No auto-commit yet\n";
        } else {
            echo "\n  ℹ Last auto-commit at offset: {$lastStoredOffset}\n";
        }
        echo "  ℹ Final offset will be stored on close()\n\n";

        return $processed;
    }

    private function simulateProcessing(\CrazyGoat\RabbitStream\Client\Message $message): void
    {
        // Simulate some processing work
        $body = $message->getBody();

        // In a real application, you would:
        // - Parse the message
        // - Validate the data
        // - Update a database
        // - Send notifications
        // - etc.

        // Small delay to simulate work
        usleep(1000); // 1ms
    }

    private function cleanup(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 6: Cleaning up...\n";

        // close() automatically stores the final offset
        $consumer->close();
        echo "  ✓ Consumer closed (final offset stored)\n";

        $this->connection->close();
        echo "  ✓ Connection closed\n";
    }
}

// Run the example
$example = new ConsumerAutoCommitExample();
$example->run();
