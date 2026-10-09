<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ConstructionCleanupTest extends TestCase
{
    public function testFailedNamedProducerConstructionCleansUpAndAttemptsDelete(): void
    {
        $sent = [];
        $timeout = new TimeoutException('QueryPublisherSequence timed out');
        $connection = $this->getMockBuilder(StreamConnection::class)
            ->onlyMethods(['request', 'sendMessage', 'readMessage'])
            ->getMock();
        $connection->method('request')->willReturnCallback(
            static function (object $request) use (&$sent, $timeout): object {
                $sent[] = $request;
                if ($request instanceof QueryPublisherSequenceRequestV1) {
                    throw $timeout;
                }
                if ($request instanceof DeletePublisherRequestV1) {
                    throw new \RuntimeException('Best-effort delete failed');
                }
                return new \stdClass();
            }
        );

        try {
            new Producer($connection, 'cleanup-stream', 7, 'named-producer');
            self::fail('Producer construction should fail when querying its sequence times out');
        } catch (TimeoutException $exception) {
            self::assertSame($timeout, $exception, 'The original exception must be rethrown unchanged');
        }

        self::assertContainsOnlyInstancesOf(DeclarePublisherRequestV1::class, [$sent[0]]);
        self::assertInstanceOf(QueryPublisherSequenceRequestV1::class, $sent[1]);
        self::assertInstanceOf(DeletePublisherRequestV1::class, $sent[2]);
        self::assertArrayNotHasKey(7, $this->property($connection, 'publisherCallbacks'));
        self::assertArrayNotHasKey(7, $this->property($connection, 'connectionLostHandlers'));
        $metadataHandlers = $this->property($connection, 'metadataUpdateHandlers');
        $streamHandlers = $metadataHandlers['cleanup-stream'] ?? [];
        self::assertIsArray($streamHandlers);
        self::assertArrayNotHasKey('publisher-7', $streamHandlers);
    }

    public function testFailedConsumerSubscribeConstructionCleansUpHandlers(): void
    {
        $failure = new ProtocolException(
            'Subscribe failed',
            responseCode: ResponseCodeEnum::STREAM_NOT_EXIST
        );
        $connection = $this->getMockBuilder(StreamConnection::class)
            ->onlyMethods(['request', 'sendMessage', 'readMessage'])
            ->getMock();
        $connection->method('request')->willReturnCallback(
            static function (object $request) use ($failure): object {
                if ($request instanceof SubscribeRequestV1) {
                    throw $failure;
                }
                return new \stdClass();
            }
        );

        try {
            new Consumer(
                $connection,
                'cleanup-stream',
                3,
                OffsetSpec::first(),
                name: 'sac-consumer',
                singleActiveConsumer: true,
            );
            self::fail('Consumer construction should fail when Subscribe is rejected');
        } catch (ProtocolException $exception) {
            self::assertSame($failure, $exception, 'The original exception must be rethrown unchanged');
        }

        self::assertArrayNotHasKey(3, $this->property($connection, 'subscriberCallbacks'));
        self::assertArrayNotHasKey(3, $this->property($connection, 'creditErrorHandlers'));
        self::assertArrayNotHasKey(3, $this->property($connection, 'consumerUpdateHandlers'));
        $metadataHandlers = $this->property($connection, 'metadataUpdateHandlers');
        $streamHandlers = $metadataHandlers['cleanup-stream'] ?? [];
        self::assertIsArray($streamHandlers);
        self::assertArrayNotHasKey('subscription-3', $streamHandlers);
    }

    /** @return array<mixed> */
    private function property(StreamConnection $connection, string $name): array
    {
        $value = (new \ReflectionProperty(StreamConnection::class, $name))->getValue($connection);
        self::assertIsArray($value);
        return $value;
    }
}
