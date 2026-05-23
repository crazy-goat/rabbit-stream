<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\CreditResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

/**
 * @group slow
 */
class CreditFlowControlTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    protected function tearDown(): void
    {
        try {
            if (
                $this->connection instanceof StreamConnection
                && $this->connection->isConnected()
                && $this->streamName !== ''
            ) {
                try {
                    $this->connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                    $this->connection->readMessage();
                } catch (\Exception) {
                    // Ignore cleanup errors — stream may already be deleted
                }
            }
        } finally {
            if ($this->connection instanceof StreamConnection && $this->connection->isConnected()) {
                $this->connection->close();
            }
            $this->connection = null;
            $this->streamName = '';
        }
    }

    public function testConsumerStopsReceivingWithoutCredit(): void
    {
        $subConnection = $this->connectAndOpen();
        $this->connection = $subConnection;
        $this->streamName = 'test-credit-flow-' . uniqid();

        $pubConnection = $this->connectAndOpen();

        try {
            // Create stream via subConnection
            $subConnection->sendMessage(new CreateRequestV1($this->streamName));
            $this->assertInstanceOf(CreateResponseV1::class, $subConnection->readMessage());

            // Declare publisher via pubConnection
            $pubConnection->sendMessage(new DeclarePublisherRequestV1(1, null, $this->streamName));
            $this->assertInstanceOf(DeclarePublisherResponseV1::class, $pubConnection->readMessage());

            // Register publisher confirm callback on pubConnection
            $pubConfirmCount = 0;
            $pubConnection->registerPublisher(1, function ($publishingIds) use (&$pubConfirmCount): void {
                $pubConfirmCount += count($publishingIds);
            }, function ($errors): void {
            });

            // Register subscriber callback on subConnection
            $deliverCount = 0;
            $subConnection->registerSubscriber(1, function () use (&$deliverCount): void {
                $deliverCount++;
            });

            // Subscribe with initialCredit = 1 (stream is empty, no delivery yet)
            $subConnection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 1));
            $this->assertInstanceOf(SubscribeResponseV1::class, $subConnection->readMessage());

            // Publish 10 messages via pubConnection — this triggers 1 delivery on sub
            $msgs1 = [];
            for ($i = 0; $i < 10; $i++) {
                $msgs1[] = new PublishedMessage($i, "m-{$i}");
            }
            $pubConnection->sendMessage(new PublishRequestV1(1, ...$msgs1));

            // Wait for publish confirm on pubConnection
            $pubConnection->readLoop(maxFrames: 1, timeout: 5.0);
            $this->assertSame(10, $pubConfirmCount, 'All 10 published messages should be confirmed');

            // Read the delivery on subConnection (consumes 1 credit, gets 1 chunk)
            $subConnection->readLoop(maxFrames: 1, timeout: 5.0);
            $this->assertSame(1, $deliverCount, 'Exactly 1 deliver frame should arrive with 1 credit');

            // Publish 10 more messages — subConnection has no credit, no delivery
            $msgs2 = [];
            for ($i = 10; $i < 20; $i++) {
                $msgs2[] = new PublishedMessage($i, "m-{$i}");
            }
            $pubConnection->sendMessage(new PublishRequestV1(1, ...$msgs2));
            $pubConnection->readLoop(maxFrames: 1, timeout: 5.0);
            $this->assertSame(20, $pubConfirmCount, 'All 20 published messages should be confirmed');

            // readLoop with short timeout on sub — no credit, no deliveries
            $subConnection->readLoop(timeout: 1.0);
            $this->assertSame(1, $deliverCount, 'No new deliveries without credit');

            // Send more credit on subConnection
            $subConnection->sendMessage(new CreditRequestV1(1, 10));

            // More deliveries should now arrive
            $subConnection->readLoop(maxFrames: 1, timeout: 5.0);
            $this->assertSame(2, $deliverCount, 'Delivery count should increase after sending credit');
        } finally {
            $pubConnection->close();
        }
    }

    public function testCreditResponseOnInvalidSubscription(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;
        $this->streamName = 'test-credit-invalid-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($this->streamName));
        $connection->readMessage();

        // Send credit for non-existent subscription (valid uint8, but no such sub)
        $connection->sendMessage(new CreditRequestV1(200, 10));

        // Should get a CreditResponse (error response)
        $response = $connection->readMessage();
        $this->assertInstanceOf(
            CreditResponseV1::class,
            $response,
            'Credit for invalid subscription should return a CreditResponse'
        );
        $this->assertNotSame(
            ResponseCodeEnum::OK->value,
            $response->getResponseCode(),
            'Credit for invalid subscription should return non-OK response code'
        );
    }
}
