<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class SubscribeTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    protected function tearDown(): void
    {
        if (!$this->connection instanceof StreamConnection) {
            return;
        }
        try {
            if ($this->connection->isConnected() && $this->streamName !== '') {
                $this->connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $this->connection->readMessage();
            }
        } catch (\Exception) {
            // Ignore cleanup errors — stream may already be deleted
        } finally {
            $this->connection->close();
        }
    }

    public function testSubscribeToStream(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-stream-' . uniqid();

        // Create a test stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Subscribe to the stream
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
    }

    public function testSubscribeWithOffsetLast(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-last-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::last(), 5));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
    }

    public function testSubscribeWithOffsetNext(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-next-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::next(), 20));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
    }

    public function testSubscribeToNonExistentStreamThrows(): void
    {
        $this->connection = $this->connectAndOpen();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('STREAM_NOT_EXIST');
        $this->connection->sendMessage(new SubscribeRequestV1(
            1,
            'non-existent-stream-' . uniqid(),
            OffsetSpec::first(),
            10
        ));
        $this->connection->readMessage();
    }

    public function testDuplicateSubscriptionIdThrows(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-duplicate-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        // First subscription should succeed
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $response = $this->connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        // Second subscription with same ID should fail with SUBSCRIPTION_ID_ALREADY_EXISTS (0x03)
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_ID_ALREADY_EXISTS');
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $this->connection->readMessage();
    }

    /**
     * Every public subscribable `OffsetSpec::TYPE_*` the library can build must
     * be accepted by the broker.
     *
     * Regression gate for #468: `OffsetSpec::TYPE_INTERVAL` (`0x0006`) is not a
     * protocol offset type, and a `Subscribe` carrying it does not merely fail —
     * the broker's connection process crashes (`{case_clause,6}` in
     * `rabbit_stream_core:parse_request/1`) and drops the TCP connection. The
     * constants are enumerated by reflection, so a future constant is exercised
     * automatically, and each type subscribes on its own connection, so a
     * broker-side crash is attributed to exactly the constant that caused it.
     * `TYPE_NONE` is excluded: the protocol allows it only in a `ConsumerUpdate`
     * reply, never in a `Subscribe`.
     */
    public function testEveryPublicSubscribeOffsetTypeIsAcceptedByBroker(): void
    {
        // Seed the stream with a few messages so a non-empty log exists for
        // every offset type to resolve against, then subscribe from a separate
        // connection per type.
        $seed = $this->createConnection();
        $this->streamName = 'test-subscribe-offset-types-' . uniqid();
        $seed->createStream($this->streamName);

        $producer = $seed->createProducer($this->streamName);
        for ($i = 0; $i < 3; $i++) {
            $producer->send("seed-{$i}");
        }
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        $types = $this->publicSubscribeTypes();
        $this->assertNotEmpty($types, 'Expected at least one subscribable OffsetSpec::TYPE_* constant');

        try {
            foreach ($types as $name => $type) {
                $connection = $this->connectAndOpen();

                try {
                    $connection->sendMessage(
                        new SubscribeRequestV1(1, $this->streamName, $this->specForType($type), 10)
                    );
                    $response = $connection->readMessage();
                } catch (\Throwable $e) {
                    $this->fail(sprintf(
                        'Broker rejected OffsetSpec::%s (%d): %s',
                        $name,
                        $type,
                        $e->getMessage()
                    ));
                } finally {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                }

                $this->assertInstanceOf(
                    SubscribeResponseV1::class,
                    $response,
                    "Broker rejected OffsetSpec::{$name} ({$type})"
                );
            }
        } finally {
            $seed->deleteStream($this->streamName);
            $seed->close();
        }
    }

    /**
     * Public `TYPE_*` constants that are valid in a `Subscribe`, keyed by name.
     *
     * @return array<string, int>
     */
    private function publicSubscribeTypes(): array
    {
        $types = [];
        foreach ((new \ReflectionClass(OffsetSpec::class))->getConstants() as $name => $value) {
            if (!str_starts_with($name, 'TYPE_') || $name === 'TYPE_NONE' || !is_int($value)) {
                continue;
            }
            $types[$name] = $value;
        }

        return $types;
    }

    /**
     * Build the smallest valid spec for a raw offset type, whether or not it
     * carries an 8-byte value.
     */
    private function specForType(int $type): OffsetSpec
    {
        try {
            return new OffsetSpec($type);
        } catch (InvalidArgumentException) {
            // Value-carrying type: the constructor requires a value.
            return new OffsetSpec($type, 0);
        }
    }
}
