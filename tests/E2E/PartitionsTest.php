<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class PartitionsTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $superStreamName = '';

    protected function tearDown(): void
    {
        if (!$this->connection instanceof StreamConnection) {
            return;
        }
        try {
            if ($this->connection->isConnected() && $this->superStreamName !== '') {
                $this->connection->sendMessage(new DeleteSuperStreamRequestV1($this->superStreamName));
                $this->connection->readMessage();
            }
        } catch (\Exception) {
            // Ignore cleanup errors — super stream may already be deleted
        } finally {
            $this->connection->close();
        }
    }

    public function testPartitionsForNonExistentSuperStreamThrows(): void
    {
        $this->connection = $this->connectAndOpen();

        $superStreamName = 'test-nonexistent-partitions-' . uniqid();

        $this->expectException(\Exception::class);
        $this->connection->sendMessage(new PartitionsRequestV1($superStreamName));
        $this->connection->readMessage();
    }

    public function testPartitionsReturnsStreamsForSuperStream(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->superStreamName = 'test-partitions-super-stream-' . uniqid();
        $partition1 = $this->superStreamName . '-0';
        $partition2 = $this->superStreamName . '-1';
        $partition3 = $this->superStreamName . '-2';

        $this->connection->sendMessage(new CreateSuperStreamRequestV1(
            $this->superStreamName,
            [$partition1, $partition2, $partition3],
            ['0', '1', '2']
        ));
        $this->connection->readMessage();

        $this->connection->sendMessage(new PartitionsRequestV1($this->superStreamName));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(PartitionsResponseV1::class, $response);
        $streams = $response->getStreams();
        $this->assertCount(3, $streams);
        $this->assertContains($partition1, $streams);
        $this->assertContains($partition2, $streams);
        $this->assertContains($partition3, $streams);
    }
}
