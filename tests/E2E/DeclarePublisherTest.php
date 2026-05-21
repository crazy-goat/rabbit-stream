<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class DeclarePublisherTest extends E2ETestCase
{
    public function testDeclarePublisherWithReference(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, 'test-publisher', 'test-stream'));
        $response = $connection->readMessage();

        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $response);

        $connection->close();
    }

    public function testDeclarePublisherWithoutReference(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'test-stream'));
        $response = $connection->readMessage();

        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $response);

        $connection->close();
    }

    public function testDeclarePublisherOnNonExistentStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('STREAM_NOT_EXIST');
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'non-existent-stream'));
        $connection->readMessage();

        $connection->close();
    }
}
