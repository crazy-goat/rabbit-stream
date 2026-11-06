<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Contract\SuperStreamProducerInterface;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every path that sends a producer name, a consumer name or an offset reference
 * checks the 255-byte broker limit before the first frame (#932).
 *
 * Frames are recorded instead of asserted with never(): the Connection destructor
 * sends a Close request when a test ends, and that request must not fail a test.
 */
class ReferenceLimitTest extends TestCase
{
    private const LIMIT = 255;

    public function testProducerNameOverLimitThrowsBeforeDeclarePublisher(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->never())->method('registerPublisher');
        $connection->expects($this->never())->method('request');
        $connection->expects($this->never())->method('sendMessage');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('producer name is 256 bytes long');

        new Producer($connection, 'test-stream', 1, str_repeat('p', self::LIMIT + 1), logger: new NullLogger());
    }

    public function testCreateProducerWithNameOverLimitSendsNoFrame(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        try {
            $wire->createProducer('test-stream', str_repeat('p', self::LIMIT + 1));
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('producer name is 256 bytes long', $e->getMessage());
        }

        $this->assertSame([], $frames);
    }

    public function testSuperStreamProducerRejectsDerivedPartitionNameOverLimitAtCreation(): void
    {
        $partition = 'orders-0';
        // Derived name is "<name>-<partition>": the name alone is one byte short of the limit.
        $name = str_repeat('n', self::LIMIT + 1 - strlen($partition) - 1);

        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames, $partition));

        try {
            $wire->createSuperStreamProducer('orders', name: $name);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('partition producer name is 256 bytes long', $e->getMessage());
        }

        $this->assertNotContains(DeclarePublisherRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testSuperStreamProducerAcceptsDerivedPartitionNameAtLimit(): void
    {
        $partition = 'orders-0';
        $name = str_repeat('n', self::LIMIT - strlen($partition) - 1);

        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames, $partition));

        $this->assertInstanceOf(
            SuperStreamProducerInterface::class,
            $wire->createSuperStreamProducer('orders', name: $name)
        );
    }

    public function testSingleActiveConsumerNameOverLimitThrowsBeforeSubscribe(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        try {
            $wire->createConsumer(
                'test-stream',
                OffsetSpec::first(),
                name: str_repeat('c', self::LIMIT + 1),
                singleActiveConsumer: true,
            );
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('single-active-consumer name is 256 bytes long', $e->getMessage());
        }

        $this->assertNotContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testSingleActiveConsumerNameAtLimitSubscribes(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        $wire->createConsumer(
            'test-stream',
            OffsetSpec::first(),
            name: str_repeat('c', self::LIMIT),
            singleActiveConsumer: true,
        );

        $this->assertContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testCreateConsumerWithAutoCommitAndNameOverLimitThrowsBeforeSubscribe(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        try {
            $wire->createConsumer(
                'test-stream',
                OffsetSpec::first(),
                name: str_repeat('c', self::LIMIT + 1),
                autoCommit: 1,
            );
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumer name is 256 bytes long', $e->getMessage());
        }

        $this->assertNotContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testCreateConsumerWithAutoCommitAndNameAtLimitSubscribes(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        $wire->createConsumer(
            'test-stream',
            OffsetSpec::first(),
            name: str_repeat('c', self::LIMIT),
            autoCommit: 1,
        );

        $this->assertContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testCreateConsumerWithoutAutoCommitAndNameOverLimitSubscribes(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        // Without auto-commit the name is never sent as an offset reference by the
        // Consumer itself, so the constructor does not check it.
        $wire->createConsumer('test-stream', OffsetSpec::first(), name: str_repeat('c', self::LIMIT + 1));

        $this->assertContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testCreateSuperStreamConsumerWithAutoCommitAndNameOverLimitThrowsBeforeSubscribe(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames, 'orders-0'));

        try {
            $wire->createSuperStreamConsumer(
                'orders',
                OffsetSpec::first(),
                name: str_repeat('c', self::LIMIT + 1),
                autoCommit: 1,
            );
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumer name is 256 bytes long', $e->getMessage());
        }

        $this->assertNotContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testCreateSuperStreamConsumerWithAutoCommitAndNameAtLimitSubscribes(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames, 'orders-0'));

        $wire->createSuperStreamConsumer(
            'orders',
            OffsetSpec::first(),
            name: str_repeat('c', self::LIMIT),
            autoCommit: 1,
        );

        $this->assertContains(SubscribeRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testCreateProducerWithNameAtLimitDeclaresPublisher(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        $wire->createProducer('test-stream', str_repeat('p', self::LIMIT));

        $this->assertContains(DeclarePublisherRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testConnectionQueryOffsetAtLimitIsSent(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        $wire->queryOffset(str_repeat('r', self::LIMIT), 'test-stream');

        $this->assertContains(QueryOffsetRequestV1::class, array_map(get_class(...), $frames));
    }

    public function testConnectionStoreOffsetOverLimitSendsNoFrame(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        try {
            $wire->storeOffset(str_repeat('r', self::LIMIT + 1), 'test-stream', 1);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumer reference is 256 bytes long', $e->getMessage());
        }

        $this->assertSame([], $frames);
    }

    public function testConnectionStoreOffsetAtLimitIsSent(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        $wire->storeOffset(str_repeat('r', self::LIMIT), 'test-stream', 1);

        $this->assertCount(1, $frames);
        $this->assertInstanceOf(StoreOffsetRequestV1::class, $frames[0]);
    }

    public function testConnectionQueryOffsetOverLimitSendsNoFrame(): void
    {
        $frames = [];
        $wire = $this->createConnectionWithMock($this->recordingMock($frames));

        try {
            $wire->queryOffset(str_repeat('r', self::LIMIT + 1), 'test-stream');
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumer reference is 256 bytes long', $e->getMessage());
        }

        $this->assertSame([], $frames);
    }

    public function testConsumerStoreOffsetOverLimitSendsNoFrame(): void
    {
        $sent = [];
        $consumer = $this->consumerWithNameRecording(str_repeat('c', self::LIMIT + 1), $sent);
        $sent = [];

        try {
            $consumer->storeOffset(1);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumer reference is 256 bytes long', $e->getMessage());
        }

        $this->assertNoOffsetFrame($sent);
    }

    public function testConsumerQueryOffsetOverLimitSendsNoFrame(): void
    {
        $sent = [];
        $consumer = $this->consumerWithNameRecording(str_repeat('c', self::LIMIT + 1), $sent);
        $sent = [];

        try {
            $consumer->queryOffset();
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumer reference is 256 bytes long', $e->getMessage());
        }

        $this->assertNoOffsetFrame($sent);
    }

    public function testConsumerSingleActiveNameOverLimitThrowsBeforeSubscribe(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->never())->method('request');
        $connection->expects($this->never())->method('sendMessage');
        $connection->expects($this->never())->method('registerConsumerUpdateHandler');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('single-active-consumer name is 256 bytes long');

        new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: str_repeat('c', self::LIMIT + 1),
            singleActiveConsumer: true,
        );
    }

    public function testConsumerWithAutoCommitAndNameOverLimitThrowsBeforeSubscribe(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->never())->method('request');
        $connection->expects($this->never())->method('sendMessage');
        $connection->expects($this->never())->method('registerCreditErrorHandler');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consumer name is 256 bytes long');

        new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: str_repeat('c', self::LIMIT + 1),
            autoCommit: 1,
        );
    }

    public function testConsumerWithAutoCommitAndNameAtLimitSubscribes(): void
    {
        $sent = [];
        $this->consumerWithNameRecording(str_repeat('c', self::LIMIT), $sent, autoCommit: 1);

        $this->assertContains(SubscribeRequestV1::class, array_map(get_class(...), $sent));
    }

    public function testConsumerStoreOffsetAtLimitSendsFrame(): void
    {
        $sent = [];
        $consumer = $this->consumerWithNameRecording(str_repeat('c', self::LIMIT), $sent);
        $sent = [];

        $consumer->storeOffset(1);

        $this->assertContains(StoreOffsetRequestV1::class, array_map(get_class(...), $sent));
    }

    public function testConsumerQueryOffsetAtLimitSendsFrame(): void
    {
        $sent = [];
        $consumer = $this->consumerWithNameRecording(str_repeat('c', self::LIMIT), $sent);
        $sent = [];

        $consumer->queryOffset();

        $this->assertContains(QueryOffsetRequestV1::class, array_map(get_class(...), $sent));
    }

    /**
     * Build a non-SAC named consumer. Every frame it sends is appended to $sent.
     *
     * @param array<int, object> $sent
     */
    private function consumerWithNameRecording(string $name, array &$sent, int $autoCommit = 0): Consumer
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->method('request')->willReturnCallback(
            function (object $request) use (&$sent): object {
                $sent[] = $request;
                if ($request instanceof QueryOffsetRequestV1) {
                    return new QueryOffsetResponseV1();
                }
                return new \stdClass();
            }
        );
        $connection->method('sendMessage')->willReturnCallback(
            function (object $request) use (&$sent): void {
                $sent[] = $request;
            }
        );

        return new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: $name,
            autoCommit: $autoCommit,
        );
    }

    /** @param array<int, object> $sent */
    private function assertNoOffsetFrame(array $sent): void
    {
        foreach ($sent as $frame) {
            $this->assertNotInstanceOf(StoreOffsetRequestV1::class, $frame);
            $this->assertNotInstanceOf(QueryOffsetRequestV1::class, $frame);
        }
    }

    /**
     * A StreamConnection mock that records every request and sendMessage frame.
     * A Partitions request gets $partition as the only partition of the super stream.
     *
     * @param array<int, object> $frames
     */
    private function recordingMock(array &$frames, ?string $partition = null): StreamConnection
    {
        $mock = $this->createMock(StreamConnection::class);
        $mock->method('request')->willReturnCallback(
            function (object $request) use (&$frames, $partition): object {
                $frames[] = $request;
                if ($request instanceof PartitionsRequestV1 && $partition !== null) {
                    return new PartitionsResponseV1([$partition]);
                }
                if ($request instanceof QueryOffsetRequestV1) {
                    return new QueryOffsetResponseV1();
                }
                if ($request instanceof QueryPublisherSequenceRequestV1) {
                    return new QueryPublisherSequenceResponseV1();
                }
                return new CloseResponseV1();
            }
        );
        $mock->method('sendMessage')->willReturnCallback(
            function (object $request) use (&$frames): void {
                $frames[] = $request;
            }
        );

        return $mock;
    }

    private function createConnectionWithMock(StreamConnection $mock): Connection
    {
        $reflection = new \ReflectionClass(Connection::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'Connection class must have a constructor');

        $connection = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($connection, $mock, new NullLogger());

        return $connection;
    }
}
