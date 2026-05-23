<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;

class ReadMessageTimeoutTest extends E2ETestCase
{
    public function testReadMessageTimesOutWhenNoDataAvailable(): void
    {
        $connection = $this->connectAndOpen();

        // Don't send any request — readMessage should block and eventually timeout
        $start = microtime(true);

        try {
            $connection->readMessage(timeout: 0.3);
            $this->fail('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }

        $elapsed = microtime(true) - $start;
        $this->assertGreaterThan(0.2, $elapsed);
        $this->assertLessThan(1.0, $elapsed);

        $connection->close();
    }

    public function testConnectionRemainsUsableAfterTimeout(): void
    {
        $connection = $this->connectAndOpen();

        // First read should timeout (no pending data)
        try {
            $connection->readMessage(timeout: 0.5);
            $this->fail('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }

        // Now send a proper request and verify we get a response
        $connection->sendMessage(new CloseRequestV1());
        $response = $connection->readMessage();

        $this->assertInstanceOf(CloseResponseV1::class, $response);

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }

    public function testReadMessageWithZeroTimeoutReturnsImmediately(): void
    {
        $connection = $this->connectAndOpen();

        $start = microtime(true);

        try {
            $connection->readMessage(timeout: 0.0);
            $this->fail('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }

        $elapsed = microtime(true) - $start;
        // With timeout 0.0, readFrame does a non-blocking poll and returns immediately
        $this->assertLessThan(0.5, $elapsed);

        $connection->close();
    }
}
