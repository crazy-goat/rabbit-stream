<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class StoreOffsetQueryOffsetE2ETest extends E2ETestCase
{
    public function testStoreAndQueryOffsetAtProtocolLevel(): void
    {
        $connection = $this->connectAndOpen();
        $stream = 'test-store-query-offset-stream-' . uniqid();
        $reference = 'test-ref-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($stream, []));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Store offset with reference
        $connection->sendMessage(new StoreOffsetRequestV1($reference, $stream, 42));
        // StoreOffset is fire-and-forget, no response expected

        // Query offset with same reference
        $connection->sendMessage(new QueryOffsetRequestV1($reference, $stream));
        $response = $connection->readMessage();

        $this->assertInstanceOf(QueryOffsetResponseV1::class, $response);
        $this->assertSame(42, $response->getOffset());

        // Cleanup
        $connection->sendMessage(new DeleteStreamRequestV1($stream));
        $connection->readMessage();
        $connection->close();
    }

    public function testQueryOffsetForNonExistentReference(): void
    {
        $connection = $this->connectAndOpen();
        $stream = 'test-nonexistent-ref-stream-' . uniqid();
        $reference = 'nonexistent-ref-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($stream, []));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Query offset with non-existent reference - should throw ProtocolException with NO_OFFSET
        $connection->sendMessage(new QueryOffsetRequestV1($reference, $stream));

        $this->expectException(\CrazyGoat\RabbitStream\Exception\ProtocolException::class);
        $this->expectExceptionMessage('0x0013');
        $connection->readMessage();

        // Cleanup
        $connection->sendMessage(new DeleteStreamRequestV1($stream));
        $connection->readMessage();
        $connection->close();
    }

    public function testStoreOffsetMultipleTimesLastValueWins(): void
    {
        $connection = $this->connectAndOpen();
        $stream = 'test-multi-store-stream-' . uniqid();
        $reference = 'test-multi-ref-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($stream, []));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Store offset multiple times
        $connection->sendMessage(new StoreOffsetRequestV1($reference, $stream, 100));
        $connection->sendMessage(new StoreOffsetRequestV1($reference, $stream, 200));
        $connection->sendMessage(new StoreOffsetRequestV1($reference, $stream, 300));

        // Query offset - should return last value (300)
        $connection->sendMessage(new QueryOffsetRequestV1($reference, $stream));
        $response = $connection->readMessage();

        $this->assertInstanceOf(QueryOffsetResponseV1::class, $response);
        $this->assertSame(300, $response->getOffset());

        // Cleanup
        $connection->sendMessage(new DeleteStreamRequestV1($stream));
        $connection->readMessage();
        $connection->close();
    }
}
