<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Response\DeletePublisherResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class DeletePublisherTest extends E2ETestCase
{
    public function testDeletePublisherAfterDeclare(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, 'test-publisher', 'test-stream'));
        $connection->readMessage();

        $connection->sendMessage(new DeletePublisherRequestV1(1));
        $response = $connection->readMessage();

        $this->assertInstanceOf(DeletePublisherResponseV1::class, $response);

        $connection->close();
    }

    public function testDeleteNonExistentPublisherThrows(): void
    {
        $connection = $this->connectAndOpen();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('PUBLISHER_NOT_EXIST');
        $connection->sendMessage(new DeletePublisherRequestV1(99));
        $connection->readMessage();

        $connection->close();
    }
}
