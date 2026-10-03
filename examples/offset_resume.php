<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Offset Resume Example
 *
 * Demonstrates:
 * - Querying stored offsets
 * - Resuming from the stored offset
 * - Complete resume pattern with error handling
 * - Named consumer best practices
 */
class OffsetResumeExample
{
    private Connection $connection;
    private string $consumerName = 'offset-resume-demo';
    private int $messagesToProcess = 30;

    public function run(): void
    {
        echo "=== Offset Resume Example ===\n\n";

        // Step 1: Create connection
        $this->createConnection();

        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();

        // Step 3: Create consumer with resume capability
        $consumer = $this->createResumingConsumer();

        // Step 4: Process messages with offset tracking
        $processed = $this->processMessages($consumer);

        // Step 5: Cleanup
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

    /**
     * Create a consumer that resumes from the last stored offset
     */
    private function createResumingConsumer(): \CrazyGoat\RabbitStream\Client\Consumer
    {
        echo "Step 3: Creating resuming consumer...\n";

        // First, try to query the last stored offset
        $tempConsumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            name: $this->consumerName
        );

        $startOffset = OffsetSpec::first();
        $resumeInfo = "Starting from beginning (no stored offset)";

        $lastOffset = $tempConsumer->queryOffset();
        $tempConsumer->close();

        if ($lastOffset === null) {
            echo "  ℹ No stored offset found (first run or offset expired)\n";
            echo "  ℹ {$resumeInfo}\n";
        } else {
            // The stored value already is the next offset to consume
            $startOffset = OffsetSpec::offset($lastOffset);
            $resumeInfo = "Resuming from offset {$lastOffset}";

            echo "  ✓ Found stored offset: {$lastOffset}\n";
            echo "  ✓ {$resumeInfo}\n";
        }

        // Create the actual consumer with the determined offset
        $consumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: $startOffset,
            name: $this->consumerName,
            initialCredit: 20
        );

        echo "  ✓ Consumer created\n";
        echo "  ℹ Consumer name: {$this->consumerName}\n\n";

        return $consumer;
    }

    /**
     * Process messages and store offset after each one
     */
    private function processMessages(\CrazyGoat\RabbitStream\Client\Consumer $consumer): int
    {
        echo "Step 4: Processing messages (max {$this->messagesToProcess})...\n";

        $processed = 0;
        $lastStoredOffset = -1;

        try {
            while ($processed < $this->messagesToProcess) {
                // Read messages with 5-second timeout
                $messages = $consumer->read(timeout: 5.0);

                if (empty($messages)) {
                    echo "  ℹ No more messages, stopping\n";
                    break;
                }

                foreach ($messages as $message) {
                    $currentOffset = $message->getOffset();

                    // Process the message
                    $success = $this->processMessage($message);

                    if ($success) {
                        // Store offset ONLY after successful processing
                        $consumer->storeOffset($currentOffset + 1);
                        $lastStoredOffset = $currentOffset;

                        $processed++;

                        echo "  ✓ [{$currentOffset}] Processed message {$processed} ";
                        echo "(offset stored)\n";

                        if ($processed >= $this->messagesToProcess) {
                            echo "  ℹ Reached message limit ({$this->messagesToProcess})\n";
                            break 2;
                        }
                    } else {
                        echo "  ✗ [{$currentOffset}] Failed to process message\n";
                        echo "  ℹ Stopping to prevent data loss\n";
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            echo "  ✗ Error: {$e->getMessage()}\n";
        }

        echo "\n  ℹ Last stored offset: {$lastStoredOffset}\n";
        echo "  ℹ On next run, will resume from offset {$lastStoredOffset}\n\n";

        return $processed;
    }

    /**
     * Simulate message processing
     * Returns true on success, false on failure
     */
    private function processMessage(\CrazyGoat\RabbitStream\Client\Message $message): bool
    {
        try {
            $body = $message->getBody();

            // Simulate processing work
            // In a real application, you might:
            // - Parse JSON/XML
            // - Validate data
            // - Update database
            // - Call external APIs
            // - etc.

            // Small delay to simulate work
            usleep(1000); // 1ms

            // Simulate occasional failures (5% chance)
            // In production, this would be real error handling
            if (rand(1, 100) <= 5) {
                echo "\n  ⚠ Simulated processing failure\n";
                return false;
            }

            return true;
        } catch (\Exception $e) {
            echo "\n  ✗ Processing error: {$e->getMessage()}\n";
            return false;
        }
    }

    private function cleanup(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 5: Cleaning up...\n";

        $consumer->close();
        echo "  ✓ Consumer closed\n";

        $this->connection->close();
        echo "  ✓ Connection closed\n";
    }
}

// Run the example
$example = new OffsetResumeExample();
$example->run();
