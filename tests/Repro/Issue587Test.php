<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Repro;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Regression tests for correlated exchanges under late replies and nested pushes. */
final class Issue587Test extends TestCase
{
    /** @var resource|null */
    private $peer;

    private ?Connection $client = null;

    protected function tearDown(): void
    {
        if (is_resource($this->peer)) {
            fclose($this->peer);
        }
        self::assertNotNull($this->client);
        $this->client = null;
    }

    public function testLateReplyAfterRequestTimeoutIsNotHandedToStreamExists(): void
    {
        $stream = $this->connectedStreamConnection();
        try {
            $stream->request(new QueryOffsetRequestV1('ref', 'late-reply'), 0.05);
            self::fail('Expected the request to time out.');
        } catch (TimeoutException) {
            // Expected.
        }

        $this->peerWrite($this->queryOffsetResponse(1, 42));
        $this->peerWrite($this->metadataResponse(2, 'late-reply', 0x0001));

        self::assertTrue($this->clientConnection($stream)->streamExists('late-reply'));
    }

    public function testCreateProducerGetsItsReplyWhenNestedRequestParksIt(): void
    {
        $stream = $this->connectedStreamConnection();
        $nestedResult = null;
        $stream->registerConsumerUpdateHandler(
            7,
            function (ConsumerUpdateResponseV1 $update) use ($stream, &$nestedResult): OffsetSpec {
                $nestedResult = $stream->request(new QueryOffsetRequestV1('ref', 'nested'), 1.0);
                return OffsetSpec::next();
            }
        );

        $this->peerWrite($this->consumerUpdatePush(99, 7, true));
        $this->peerWrite($this->declarePublisherResponse(1));
        $this->peerWrite($this->queryOffsetResponse(2, 5));
        if (!is_resource($this->peer)) {
            self::fail('Expected the peer socket to be open.');
        }
        stream_socket_shutdown($this->peer, STREAM_SHUT_WR);

        $producer = $this->clientConnection($stream)->createProducer('nested');
        self::assertInstanceOf(QueryOffsetResponseV1::class, $nestedResult);
        self::assertInstanceOf(\CrazyGoat\RabbitStream\Client\Producer::class, $producer);
    }

    private function connectedStreamConnection(): StreamConnection
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('stream_socket_pair() failed');
        }
        [$client, $this->peer] = $pair;
        stream_set_blocking($client, false);
        $connection = new StreamConnection('127.0.0.1', 1);
        (new \ReflectionProperty($connection, 'stream'))->setValue($connection, $client);
        (new \ReflectionProperty($connection, 'connected'))->setValue($connection, true);

        return $connection;
    }

    private function clientConnection(StreamConnection $stream): Connection
    {
        $reflection = new \ReflectionClass(Connection::class);
        $connection = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($connection, $stream, new NullLogger());

        return $this->client = $connection;
    }

    private function peerWrite(string $payload): void
    {
        if (!is_resource($this->peer)) {
            self::fail('Expected the peer socket to be open.');
        }
        fwrite($this->peer, pack('N', strlen($payload)) . $payload);
    }

    private function str(string $value): string
    {
        return pack('n', strlen($value)) . $value;
    }

    private function queryOffsetResponse(int $correlationId, int $offset): string
    {
        return pack('nnNn', 0x800b, 1, $correlationId, 0x0001) . pack('J', $offset);
    }

    private function declarePublisherResponse(int $correlationId): string
    {
        return pack('nnNn', 0x8001, 1, $correlationId, 0x0001);
    }

    private function metadataResponse(int $correlationId, string $stream, int $code): string
    {
        $brokers = pack('N', 1) . pack('n', 0) . $this->str('localhost') . pack('N', 5552);
        $metadata = pack('N', 1) . $this->str($stream) . pack('nn', $code, 0) . pack('N', 0);

        return pack('nnN', 0x800f, 1, $correlationId) . $brokers . $metadata;
    }

    private function consumerUpdatePush(int $correlationId, int $subscriptionId, bool $active): string
    {
        return pack('nnNCC', 0x001a, 1, $correlationId, $subscriptionId, $active ? 1 : 0);
    }
}
