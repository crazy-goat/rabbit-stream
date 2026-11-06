<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Repro;

use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

/** Regression tests for late replies to requests that timed out (GitHub #939). */
final class Issue939Test extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
    }

    public function testLateReplyForEvictedAbandonedIdIsNotParked(): void
    {
        [$connection, $peer] = $this->connectedPair();

        // 65 requests time out. The cap of 64 evicts id 1 from the abandoned set.
        for ($i = 1; $i <= 65; $i++) {
            try {
                $connection->request(new CreateRequestV1('timeout-' . $i), 0.01);
                self::fail('Expected TimeoutException');
            } catch (TimeoutException) {
                // expected
            }
        }

        // The late reply for id 1 arrives before the reply for request 66.
        fwrite($peer, $this->frame($this->createReply(1)) . $this->frame($this->createReply(66)));

        $response = $connection->request(new CreateRequestV1('after-cap'), 1.0);
        self::assertInstanceOf(CreateResponseV1::class, $response);
        self::assertSame(66, $response->getCorrelationId());

        // No caller waits for id 1, so its reply must not stay parked.
        self::assertSame([], $this->property($connection, 'pendingResponses'));
    }

    public function testMalformedLateReplyForAbandonedIdDoesNotFailNextRequest(): void
    {
        [$connection, $peer] = $this->connectedPair();

        try {
            $connection->request(new CreateRequestV1('timed-out'), 0.05);
            self::fail('Expected TimeoutException');
        } catch (TimeoutException) {
            // expected: id 1 is abandoned
        }

        // The late reply for id 1 has no response code. The valid reply for id 2 follows.
        $malformed = pack('nnN', 0x800d, 1, 1);
        fwrite($peer, $this->frame($malformed) . $this->frame($this->createReply(2)));

        $response = $connection->request(new CreateRequestV1('next'), 1.0);
        self::assertInstanceOf(CreateResponseV1::class, $response);
        self::assertSame(2, $response->getCorrelationId());
        self::assertSame([], $this->property($connection, 'abandonedCorrelationIds'));
    }

    public function testMalformedLateReplyForEvictedIdDoesNotFailNextRequest(): void
    {
        [$connection, $peer] = $this->connectedPair();

        for ($i = 1; $i <= 65; $i++) {
            try {
                $connection->request(new CreateRequestV1('timeout-' . $i), 0.01);
                self::fail('Expected TimeoutException');
            } catch (TimeoutException) {
                // expected
            }
        }

        // Id 1 is evicted from the abandoned set. Its malformed late reply must still be dropped.
        $malformed = pack('nnN', 0x800d, 1, 1);
        fwrite($peer, $this->frame($malformed) . $this->frame($this->createReply(66)));

        $response = $connection->request(new CreateRequestV1('after-cap'), 1.0);
        self::assertInstanceOf(CreateResponseV1::class, $response);
        self::assertSame(66, $response->getCorrelationId());
    }

    public function testCloseClearsParkedResponses(): void
    {
        [$connection] = $this->connectedPair();

        $parked = new \ReflectionProperty($connection, 'pendingResponses');
        $parked->setValue($connection, [['correlationId' => 5, 'result' => new CreateResponseV1()]]);

        $connection->close();

        self::assertSame([], $parked->getValue($connection));
    }

    /**
     * @return array{StreamConnection, resource}
     */
    private function connectedPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('stream_socket_pair() failed');
        }
        [$clientSocket, $peer] = $pair;
        $this->sockets[] = $clientSocket;
        $this->sockets[] = $peer;

        stream_set_blocking($clientSocket, false);
        stream_set_blocking($peer, false);

        $connection = new StreamConnection('127.0.0.1', 5552);
        (new \ReflectionProperty($connection, 'stream'))->setValue($connection, $clientSocket);
        (new \ReflectionProperty($connection, 'connected'))->setValue($connection, true);

        return [$connection, $peer];
    }

    private function property(StreamConnection $connection, string $name): mixed
    {
        return (new \ReflectionProperty($connection, $name))->getValue($connection);
    }

    private function createReply(int $correlationId): string
    {
        return pack('nnN', 0x800d, 1, $correlationId) . pack('n', 1);
    }

    private function frame(string $payload): string
    {
        return pack('N', strlen($payload)) . $payload;
    }
}
