<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class RouteTest extends E2ETestCase
{
    public function testRouteForNonExistentSuperStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-nonexistent-route-' . uniqid();
        $routingKey = 'test-key';

        $this->expectException(\Exception::class);
        $connection->sendMessage(new RouteRequestV1($routingKey, $superStreamName));
        $connection->readMessage();

        $connection->close();
    }

    public function testRouteReturnsStreamsForRoutingKey(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-route-super-stream-' . uniqid();
        $partition1 = $superStreamName . '-partition1';
        $partition2 = $superStreamName . '-partition2';
        $partition3 = $superStreamName . '-partition3';

        // Create super stream with partitions and binding keys
        $connection->sendMessage(new CreateSuperStreamRequestV1(
            $superStreamName,
            [$partition1, $partition2, $partition3],
            ['key1', 'key2', 'key3']
        ));
        $response = $connection->readMessage();
        $this->assertInstanceOf(CreateSuperStreamResponseV1::class, $response);

        // Query route with binding key 'key1' - should return partition1
        $connection->sendMessage(new RouteRequestV1('key1', $superStreamName));
        $routeResponse = $connection->readMessage();

        $this->assertInstanceOf(RouteResponseV1::class, $routeResponse);
        $streams = $routeResponse->getStreams();
        $this->assertCount(1, $streams);
        $this->assertSame($partition1, $streams[0]);

        // Query route with binding key 'key2' - should return partition2
        $connection->sendMessage(new RouteRequestV1('key2', $superStreamName));
        $routeResponse2 = $connection->readMessage();

        $this->assertInstanceOf(RouteResponseV1::class, $routeResponse2);
        $streams2 = $routeResponse2->getStreams();
        $this->assertCount(1, $streams2);
        $this->assertSame($partition2, $streams2[0]);

        // Cleanup - delete super stream
        $connection->sendMessage(new DeleteSuperStreamRequestV1($superStreamName));
        $deleteResponse = $connection->readMessage();
        $this->assertInstanceOf(DeleteSuperStreamResponseV1::class, $deleteResponse);

        $connection->close();
    }
}
