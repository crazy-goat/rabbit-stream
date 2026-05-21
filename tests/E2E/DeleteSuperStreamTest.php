<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class DeleteSuperStreamTest extends E2ETestCase
{
    public function testDeleteSuperStream(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-delete-super-stream-' . uniqid();
        $partition1 = $superStreamName . '-partition1';
        $partition2 = $superStreamName . '-partition2';
        $partition3 = $superStreamName . '-partition3';

        // Create super stream
        $connection->sendMessage(new CreateSuperStreamRequestV1(
            $superStreamName,
            [$partition1, $partition2, $partition3],
            ['key1', 'key2', 'key3'],
            ['max-length-bytes' => '1000000']
        ));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateSuperStreamResponseV1::class, $createResponse);

        // Verify super stream exists by querying partitions
        $connection->sendMessage(new PartitionsRequestV1($superStreamName));
        $partitionsResponse = $connection->readMessage();
        $this->assertInstanceOf(PartitionsResponseV1::class, $partitionsResponse);
        $this->assertCount(3, $partitionsResponse->getStreams());

        // Delete super stream
        $connection->sendMessage(new DeleteSuperStreamRequestV1($superStreamName));
        $deleteResponse = $connection->readMessage();
        $this->assertInstanceOf(DeleteSuperStreamResponseV1::class, $deleteResponse);

        // Verify super stream no longer exists (partitions query should fail)
        $connection->sendMessage(new PartitionsRequestV1($superStreamName));
        $this->expectException(\Exception::class);
        $connection->readMessage();

        $connection->close();
    }

    public function testDeleteNonExistentSuperStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-delete-nonexistent-super-stream-' . uniqid();

        // Delete should fail for non-existent super stream
        $this->expectException(\Exception::class);
        $connection->sendMessage(new DeleteSuperStreamRequestV1($superStreamName));
        $connection->readMessage();

        $connection->close();
    }
}
