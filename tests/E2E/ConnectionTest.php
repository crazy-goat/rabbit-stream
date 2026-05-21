<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    public function testConnectionCreateAndStreamManagement(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-connection-stream-' . uniqid();

        // Create stream
        $connection->createStream($streamName);

        // Verify stream exists
        $this->assertTrue($connection->streamExists($streamName));

        // Delete stream
        $connection->deleteStream($streamName);

        // Verify stream no longer exists
        $this->assertFalse($connection->streamExists($streamName));

        // Close connection gracefully
        $connection->close();
    }

    public function testCreateStreamWithArguments(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-connection-stream-args-' . uniqid();

        // Create stream with arguments
        $connection->createStream($streamName, [
            'max-length-bytes' => '1000000',
            'max-age' => '1h',
        ]);

        // Verify stream exists
        $this->assertTrue($connection->streamExists($streamName));

        // Cleanup
        $connection->deleteStream($streamName);
        $connection->close();
    }

    public function testGetMetadata(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-metadata-stream-' . uniqid();
        $connection->createStream($streamName);

        $metadata = $connection->getMetadata([$streamName]);

        $this->assertNotEmpty($metadata->getStreamMetadata());
        $this->assertSame($streamName, $metadata->getStreamMetadata()[0]->getStreamName());

        // Cleanup
        $connection->deleteStream($streamName);
        $connection->close();
    }

    public function testMetadataForMultipleStreams(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $existing1 = 'test-meta-multi-1-' . uniqid();
        $existing2 = 'test-meta-multi-2-' . uniqid();
        $nonExistent = 'test-meta-nonexistent-' . uniqid();

        $connection->createStream($existing1);
        $connection->createStream($existing2);

        $metadata = $connection->getMetadata([$existing1, $nonExistent, $existing2]);

        $streamMetadata = $metadata->getStreamMetadata();
        $this->assertCount(3, $streamMetadata);

        // Build a map of stream name → response code for easier assertions
        $codeByStream = [];
        foreach ($streamMetadata as $meta) {
            $codeByStream[$meta->getStreamName()] = $meta->getResponseCode();
        }

        // Existing streams → OK
        $this->assertArrayHasKey($existing1, $codeByStream);
        $this->assertSame(ResponseCodeEnum::OK->value, $codeByStream[$existing1]);

        $this->assertArrayHasKey($existing2, $codeByStream);
        $this->assertSame(ResponseCodeEnum::OK->value, $codeByStream[$existing2]);

        // Non-existent stream → STREAM_NOT_EXIST
        $this->assertArrayHasKey($nonExistent, $codeByStream);
        $this->assertSame(ResponseCodeEnum::STREAM_NOT_EXIST->value, $codeByStream[$nonExistent]);

        // Cleanup
        $connection->deleteStream($existing1);
        $connection->deleteStream($existing2);
        $connection->close();
    }

    public function testGetStreamStats(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-stats-stream-' . uniqid();
        $connection->createStream($streamName);

        $connection->getStreamStats($streamName);

        $this->addToAssertionCount(1);

        // Cleanup
        $connection->deleteStream($streamName);
        $connection->close();
    }

    public function testCreateDuplicateStreamThrows(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-duplicate-stream-' . uniqid();

        // Create stream first time
        $connection->createStream($streamName);

        // Second create should throw
        $this->expectException(\Exception::class);
        $connection->createStream($streamName);

        // Cleanup (won't be reached due to exception)
        $connection->deleteStream($streamName);
        $connection->close();
    }
}
