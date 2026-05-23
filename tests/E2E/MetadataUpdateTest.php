<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class MetadataUpdateTest extends E2ETestCase
{
    private ?StreamConnection $subscriberConnection = null;
    private ?StreamConnection $adminConnection = null;
    private string $streamName = '';

    protected function tearDown(): void
    {
        $errors = [];

        $this->closeConnectionSafely($this->subscriberConnection, $errors);

        $this->closeConnectionSafely($this->adminConnection, $errors, deleteStream: true);

        $this->subscriberConnection = null;
        $this->adminConnection = null;
        $this->streamName = '';

        if ($errors !== []) {
            throw $errors[0];
        }
    }

    /** @param list<\Throwable> $errors */
    private function closeConnectionSafely(
        ?StreamConnection $connection,
        array &$errors,
        bool $deleteStream = false
    ): void {
        if (!$connection instanceof StreamConnection || !$connection->isConnected()) {
            return;
        }

        if ($deleteStream) {
            try {
                $connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $connection->readMessage();
            } catch (\Exception) {
            }
        }

        try {
            $connection->close();
        } catch (\Exception $e) {
            $errors[] = $e;
        }
    }

    public function testMetadataUpdateCallbackOnStreamDeletion(): void
    {
        $this->subscriberConnection = $this->connectAndOpen();
        $this->adminConnection = $this->connectAndOpen();
        $this->streamName = 'test-metadata-update-' . uniqid();

        // Create a test stream
        $this->subscriberConnection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $this->subscriberConnection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Register metadata update callback
        $metadataUpdate = null;
        $this->subscriberConnection->onMetadataUpdate(
            function (MetadataUpdateResponseV1 $update) use (&$metadataUpdate): void {
                $metadataUpdate = $update;
            }
        );

        // Subscribe to the stream on conn1
        $this->subscriberConnection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $this->subscriberConnection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // Delete the stream from the admin connection
        $this->adminConnection->sendMessage(new DeleteStreamRequestV1($this->streamName));
        $deleteResponse = $this->adminConnection->readMessage();
        $this->assertInstanceOf(DeleteStreamResponseV1::class, $deleteResponse);

        // Call readLoop on subscriber connection — should receive MetadataUpdate
        $this->subscriberConnection->readLoop(maxFrames: 1, timeout: 3.0);

        $this->assertInstanceOf(MetadataUpdateResponseV1::class, $metadataUpdate);
        $this->assertSame(
            $this->streamName,
            $metadataUpdate->getStream(),
            'MetadataUpdate should contain the deleted stream name'
        );
        $this->assertNotSame(0, $metadataUpdate->getCode(), 'MetadataUpdate should have a valid response code');
    }
}
