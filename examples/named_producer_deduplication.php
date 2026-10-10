<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use LogicException;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Named Producer Deduplication Example
 *
 * Demonstrates:
 * - Creating a named producer
 * - Publishing with auto-assigned publishing IDs
 * - Simulating a process restart (disconnect/reconnect)
 * - Resuming from the broker's stored sequence instead of replaying
 * - Why the broker never sees a duplicate publishing ID from this client
 */
class NamedProducerDeduplicationExample
{
    private string $producerName = 'order-producer';
    private string $streamName = 'orders-stream';

    /** @var array<int, string> Message body keyed by the publishing ID it was sent with */
    private array $sentMessages = [];

    /** @var array<int, string> Confirmed message body keyed by publishing ID */
    private array $confirmedMessages = [];

    /** @var array<int, int> Failure code keyed by publishing ID */
    private array $failedMessages = [];

    public function run(): void
    {
        echo "=== Named Producer Deduplication Example ===\n\n";

        // Phase 1: Initial connection and publishing
        echo "PHASE 1: Initial Connection\n";
        echo str_repeat('-', 40) . "\n";
        $connection1 = $this->createConnection();
        $this->createStream($connection1);
        $producer1 = $this->createNamedProducer($connection1);

        // Publish messages 1-5. A named producer starts at querySequence() + 1,
        // so on a fresh stream its first message gets publishing ID 1.
        echo "Publishing messages 1-5...\n";
        for ($i = 1; $i <= 5; $i++) {
            $message = "Order #{$i}";
            $producer1->send($message);
            $publishingId = $producer1->getLastPublishingId();
            if ($publishingId === null) {
                throw new LogicException('A publishing ID should be assigned after sending a message.');
            }
            $this->sentMessages[$publishingId] = $message;
            echo "  → Sent {$message} (ID: {$publishingId})\n";
        }

        $this->waitForConfirms($producer1);
        $this->showStatus("After Phase 1");

        // Phase 2: Simulate a process restart
        echo "\nPHASE 2: Simulating a Restart\n";
        echo str_repeat('-', 40) . "\n";
        echo "Closing connection (simulating process exit)...\n";
        $producer1->close();
        $connection1->close();
        echo "  ✓ Connection closed\n";

        // Phase 3: Reconnect with the same producer name
        echo "\nPHASE 3: Reconnecting\n";
        echo str_repeat('-', 40) . "\n";
        $connection2 = $this->createConnection();
        $producer2 = $this->createNamedProducer($connection2);

        // Phase 4: Resume after the restart
        echo "\nPHASE 4: Resuming After Restart\n";
        echo str_repeat('-', 40) . "\n";

        // The broker has durably stored everything up to ID 5. The producer
        // already resumed there in its constructor; querySequence() returns the
        // same value, so the application knows where to continue.
        $resumeFrom = $producer2->querySequence();
        echo "Broker stored publishing IDs up to: {$resumeFrom}\n";
        echo "Next publishing ID will be: " . ($resumeFrom + 1) . "\n";
        echo "getLastPublishingId() reports: " . $producer2->getLastPublishingId() . "\n";

        // Continue from ID 6. The client does not replay IDs 1-5, so the broker
        // never receives a publishing ID it has already stored.
        echo "Publishing messages 6-7...\n";
        for ($i = 6; $i <= 7; $i++) {
            $message = "Order #{$i}";
            $producer2->send($message);
            $publishingId = $producer2->getLastPublishingId();
            if ($publishingId === null) {
                throw new LogicException('A publishing ID should be assigned after sending a message.');
            }
            $this->sentMessages[$publishingId] = $message;
            echo "  → Sent {$message} (ID: {$publishingId})\n";
        }

        $this->waitForConfirms($producer2);
        $this->showStatus("After Phase 4");

        // Phase 5: Cleanup
        echo "\nPHASE 5: Cleanup\n";
        echo str_repeat('-', 40) . "\n";
        $producer2->close();
        $connection2->close();
        echo "  ✓ Cleanup complete\n";

        // Summary
        echo "\n=== Summary ===\n";
        echo "Total confirmed: " . count($this->confirmedMessages) . "\n";
        echo "Total failed: " . count($this->failedMessages) . "\n";
        echo "\nConfirmed messages:\n";
        ksort($this->confirmedMessages);
        foreach ($this->confirmedMessages as $id => $message) {
            echo "  #{$id}: {$message}\n";
        }
    }

    private function createConnection(): Connection
    {
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

        return Connection::create(
            host: $host,
            port: $port,
            user: 'guest',
            password: 'guest',
        );
    }

    private function createStream(Connection $connection): void
    {
        try {
            $connection->createStream($this->streamName, [
                'max-length-bytes' => '1000000000',
            ]);
            echo "  ✓ Stream '{$this->streamName}' created\n";
        } catch (\Exception) {
            echo "  ℹ Stream may already exist\n";
        }
    }

    private function createNamedProducer(Connection $connection): ProducerInterface
    {
        echo "Creating named producer '{$this->producerName}'...\n";

        $producer = $connection->createProducer(
            $this->streamName,
            name: $this->producerName,
            onConfirm: function (ConfirmationStatus $status): void {
                $id = $status->getPublishingId();
                if ($id === null) {
                    throw new LogicException('A confirmation should include a publishing ID.');
                }
                if ($status->isConfirmed()) {
                    $this->confirmedMessages[$id] = $this->sentMessages[$id] ?? '(unknown)';
                    echo "    ✓ Confirmed: #{$id}\n";
                } else {
                    $errorCode = $status->getErrorCode();
                    if ($errorCode === null) {
                        throw new LogicException('A failed confirmation should include an error code.');
                    }
                    $this->failedMessages[$id] = $errorCode;
                    echo "    ✗ Failed: #{$id} (code: {$errorCode})\n";
                }
            }
        );

        echo "  ✓ Producer created\n";

        return $producer;
    }

    private function waitForConfirms(ProducerInterface $producer): void
    {
        try {
            $producer->waitForConfirms(timeout: 5.0);
            echo "  ✓ All confirms received\n";
        } catch (\CrazyGoat\RabbitStream\Exception\TimeoutException) {
            echo "  ⚠ Timeout waiting for confirms\n";
        }
    }

    private function showStatus(string $phase): void
    {
        echo "\n  Status [{$phase}]:\n";
        echo "    - Confirmed: " . count($this->confirmedMessages) . "\n";
        echo "    - Failed: " . count($this->failedMessages) . "\n";
    }
}

// Run the example
$example = new NamedProducerDeduplicationExample();
$example->run();
