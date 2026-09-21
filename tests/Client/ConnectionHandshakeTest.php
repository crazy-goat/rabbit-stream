<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\CommandVersion;
use CrazyGoat\RabbitStream\VO\KeyValue;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConnectionHandshakeTest extends TestCase
{
    public function testCreateThrowsOnUnexpectedPeerPropertiesResponse(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturn(new SaslAuthenticateResponseV1());

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected ' . PeerPropertiesResponseV1::class);

        Connection::create(streamConnection: $streamConnection);
    }

    public function testCreateThrowsWhenPlainMechanismNotSupported(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['EXTERNAL', 'SCRAM-SHA-256']),
            );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('PLAIN SASL mechanism not supported by server');

        Connection::create(streamConnection: $streamConnection);
    }

    public function testCreateThrowsOnUnexpectedSaslHandshakeResponse(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslAuthenticateResponseV1(),
            );

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected ' . SaslHandshakeResponseV1::class);

        Connection::create(streamConnection: $streamConnection);
    }

    public function testCreateThrowsOnUnexpectedSaslAuthenticateResponse(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new OpenResponseV1(),
            );

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected ' . SaslAuthenticateResponseV1::class);

        Connection::create(streamConnection: $streamConnection);
    }

    public function testCreateThrowsOnUnexpectedTuneRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new OpenResponseV1(),
            );

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected ' . TuneRequestV1::class);

        Connection::create(streamConnection: $streamConnection);
    }

    public function testCreateThrowsOnUnexpectedOpenResponse(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new SaslAuthenticateResponseV1(),
            );

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected ' . OpenResponseV1::class);

        Connection::create(streamConnection: $streamConnection);
    }

    public function testCreateNegotiatesTuneValuesCorrectly(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $streamConnection->expects($this->once())
            ->method('setMaxFrameSize')
            ->with(131072);

        $streamConnection->method('close');

        $connection = Connection::create(
            requestedFrameMax: 262144,
            requestedHeartbeat: 30,
            streamConnection: $streamConnection,
        );

        $tuneResponses = array_filter(
            $capturedRequests,
            fn(object $r): bool => $r instanceof TuneResponseV1
        );
        $this->assertCount(1, $tuneResponses);

        unset($connection);
    }

    public function testCreateUsesServerTuneValuesWhenNoClientPreference(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $streamConnection->expects($this->once())
            ->method('setMaxFrameSize')
            ->with(131072);

        $streamConnection->method('close');

        $connection = Connection::create(streamConnection: $streamConnection);

        $tuneResponses = array_filter(
            $capturedRequests,
            fn(object $r): bool => $r instanceof TuneResponseV1
        );
        $this->assertCount(1, $tuneResponses);

        unset($connection);
    }

    public function testCreateDoesNotSetMaxFrameSizeWhenZero(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(0, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');

        $streamConnection->expects($this->never())
            ->method('setMaxFrameSize');

        $streamConnection->method('close');

        $connection = Connection::create(streamConnection: $streamConnection);

        unset($connection);
    }

    public function testCreateCapsMaxFrameSizeAtDefaultWhenNegotiatedExceedsDefaultAndNoExplicitRequest(): void
    {
        // Regression test for GH #398: a broker Tune with frameMax = 0xFFFFFFFF
        // (or any value above the default safety cap) must not blow the incoming
        // control-frame cap open just because the caller didn't request a specific
        // frame_max — negotiation may only ever LOWER the cap from its default.
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(0xFFFFFFFF, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');

        $streamConnection->expects($this->once())
            ->method('setMaxFrameSize')
            ->with(StreamConnection::DEFAULT_MAX_FRAME_SIZE);

        $preOpenCalls = [];
        $streamConnection->expects($this->exactly(2))
            ->method('setPreOpenMaxFrameSize')
            ->willReturnCallback(function (int $size) use (&$preOpenCalls): void {
                $preOpenCalls[] = $size;
            });

        $streamConnection->expects($this->once())
            ->method('setOutgoingMaxFrameSize')
            ->with(0xFFFFFFFF);

        $streamConnection->method('close');

        $connection = Connection::create(streamConnection: $streamConnection);

        // Seeded before the handshake, then explicitly lifted after Open.
        $this->assertSame([StreamConnection::DEFAULT_INITIAL_FRAME_SIZE, 0], $preOpenCalls);

        unset($connection);
    }

    public function testCreateAllowsExplicitFrameMaxAboveDefault(): void
    {
        // A caller explicitly passing a requestedFrameMax above the default safety
        // cap is a deliberate raise and must be honored as-is.
        $explicitFrameMax = 20 * 1024 * 1024;

        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(0xFFFFFFFF, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');

        $streamConnection->expects($this->once())
            ->method('setMaxFrameSize')
            ->with($explicitFrameMax);

        $streamConnection->method('close');

        $connection = Connection::create(
            requestedFrameMax: $explicitFrameMax,
            streamConnection: $streamConnection,
        );

        unset($connection);
    }

    public function testCreateSetsDefaultMaxDeliverFrameSize(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $streamConnection->expects($this->once())
            ->method('setMaxDeliverFrameSize')
            ->with(StreamConnection::DEFAULT_MAX_DELIVER_FRAME_SIZE);

        $connection = Connection::create(streamConnection: $streamConnection);

        unset($connection);
    }

    public function testCreatePassesThroughCustomMaxDeliverFrameSize(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $streamConnection->expects($this->once())
            ->method('setMaxDeliverFrameSize')
            ->with(128 * 1024 * 1024);

        $connection = Connection::create(
            maxDeliverFrameSize: 128 * 1024 * 1024,
            streamConnection: $streamConnection,
        );

        unset($connection);
    }

    public function testCreateThrowsOnNegativeMaxDeliverFrameSize(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxDeliverFrameSize must not be negative');

        Connection::create(maxDeliverFrameSize: -1, streamConnection: $streamConnection);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidCreateArguments(): array
    {
        return [
            'requestedFrameMax' => ['requestedFrameMax', 'requestedFrameMax must not be negative'],
            'requestedHeartbeat' => ['requestedHeartbeat', 'requestedHeartbeat must not be negative'],
            'maxDeliverFrameSize' => ['maxDeliverFrameSize', 'maxDeliverFrameSize must not be negative'],
            'socketTimeout' => ['socketTimeout', 'socketTimeout must be greater than 0'],
            'initialFrameMax' => ['initialFrameMax', 'initialFrameMax must not be negative'],
        ];
    }

    /**
     * Connection::create() is the library's main entry point; before #465 its four
     * argument checks threw the *global* InvalidArgumentException, so the one catch
     * the #242 hierarchy exists for missed them. The library class extends the native
     * one, so catch (\InvalidArgumentException) callers are unaffected — asserted here
     * alongside the interface.
     *
     * @dataProvider invalidCreateArguments
     */
    public function testCreateRejectsInvalidArgumentsInsideTheLibraryHierarchy(
        string $argument,
        string $expectedMessage
    ): void {
        $streamConnection = $this->createMock(StreamConnection::class);

        try {
            match ($argument) {
                'requestedFrameMax' => Connection::create(
                    requestedFrameMax: -1,
                    streamConnection: $streamConnection,
                ),
                'requestedHeartbeat' => Connection::create(
                    requestedHeartbeat: -1,
                    streamConnection: $streamConnection,
                ),
                'maxDeliverFrameSize' => Connection::create(
                    maxDeliverFrameSize: -1,
                    streamConnection: $streamConnection,
                ),
                'socketTimeout' => Connection::create(
                    streamConnection: $streamConnection,
                    socketTimeout: 0.0,
                ),
                'initialFrameMax' => Connection::create(
                    streamConnection: $streamConnection,
                    initialFrameMax: -1,
                ),
                default => $this->fail('Unhandled argument name: ' . $argument),
            };
            $this->fail('Expected Connection::create() to reject ' . $argument);
        } catch (RabbitStreamExceptionInterface $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertSame($expectedMessage, $e->getMessage());
        }
    }

    public function testCreateSetsOutgoingMaxFrameSizeToNegotiatedValue(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $preOpenCalls = [];
        $streamConnection->expects($this->exactly(2))
            ->method('setPreOpenMaxFrameSize')
            ->willReturnCallback(function (int $size) use (&$preOpenCalls): void {
                $preOpenCalls[] = $size;
            });

        $streamConnection->expects($this->once())
            ->method('setOutgoingMaxFrameSize')
            ->with(131072);

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertSame([StreamConnection::DEFAULT_INITIAL_FRAME_SIZE, 0], $preOpenCalls);

        unset($connection);
    }

    public function testCreateSeedsInitialFrameCeilingBeforeAnyHandshakeMessage(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        /** @var list<object> $queue */
        $queue = [
            $this->peerPropertiesResponse(),
            new SaslHandshakeResponseV1(['PLAIN']),
            new SaslAuthenticateResponseV1(),
            new TuneRequestV1(131072, 60),
            new OpenResponseV1(),
            $this->commandVersionsResponse(),
        ];

        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $events = [];
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$queue, &$events): object {
                $response = array_shift($queue);
                if ($response === null) {
                    throw new \RuntimeException('readMessage() called more times than canned responses');
                }
                $events[] = ['readMessage', $response::class];
                return $response;
            });
        $streamConnection->method('setPreOpenMaxFrameSize')
            ->willReturnCallback(function (int $size) use (&$events): void {
                $events[] = ['setPreOpenMaxFrameSize', $size];
            });
        $streamConnection->method('setOutgoingMaxFrameSize')
            ->willReturnCallback(function (int $size) use (&$events): void {
                $events[] = ['setOutgoingMaxFrameSize', $size];
            });
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$events): void {
                $events[] = ['sendMessage', $request::class];
            });

        $connection = Connection::create(streamConnection: $streamConnection);

        // The pre-Open ceiling must be seeded before the first handshake frame,
        // and the negotiated cap must only be applied after the readMessage()
        // that returned OpenResponseV1 (#379). The full ordered log pins that.
        $this->assertSame([
            ['setPreOpenMaxFrameSize', StreamConnection::DEFAULT_INITIAL_FRAME_SIZE],
            ['sendMessage', PeerPropertiesRequestV1::class],
            ['readMessage', PeerPropertiesResponseV1::class],
            ['sendMessage', SaslHandshakeRequestV1::class],
            ['readMessage', SaslHandshakeResponseV1::class],
            ['sendMessage', SaslAuthenticateRequestV1::class],
            ['readMessage', SaslAuthenticateResponseV1::class],
            ['readMessage', TuneRequestV1::class],
            ['sendMessage', TuneResponseV1::class],
            ['sendMessage', OpenRequestV1::class],
            ['readMessage', OpenResponseV1::class],
            ['setOutgoingMaxFrameSize', 131072],
            ['setPreOpenMaxFrameSize', 0],
            ['sendMessage', ExchangeCommandVersionsRequestV1::class],
            ['readMessage', ExchangeCommandVersionsResponseV1::class],
        ], $events);

        unset($connection);
    }

    public function testCreateUsesConfiguredInitialFrameMaxForPreOpenCeiling(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $preOpenCalls = [];
        $streamConnection->expects($this->exactly(2))
            ->method('setPreOpenMaxFrameSize')
            ->willReturnCallback(function (int $size) use (&$preOpenCalls): void {
                $preOpenCalls[] = $size;
            });

        $streamConnection->expects($this->once())
            ->method('setOutgoingMaxFrameSize')
            ->with(131072);

        $connection = Connection::create(
            streamConnection: $streamConnection,
            initialFrameMax: 65536,
        );

        $this->assertSame([65536, 0], $preOpenCalls);

        unset($connection);
    }

    public function testCreatePassesVhostToOpenRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $connection = Connection::create(
            vhost: '/my-vhost',
            streamConnection: $streamConnection,
        );

        $openRequests = array_filter(
            $capturedRequests,
            fn(object $r): bool => $r instanceof OpenRequestV1
        );
        $this->assertCount(1, $openRequests);

        $openRequest = array_values($openRequests)[0];
        $this->assertSame('/my-vhost', $openRequest->toArray()['vhost']);

        unset($connection);
    }

    public function testCreatePassesCustomSerializerAndLogger(): void
    {
        $serializer = $this->createMock(BinarySerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $connection = Connection::create(
            serializer: $serializer,
            logger: $logger,
            streamConnection: $streamConnection,
        );

        $this->assertInstanceOf(Connection::class, $connection);

        unset($connection);
    }

    public function testCreateSuccessfulHandshake(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertCount(6, $capturedRequests);
        $this->assertInstanceOf(PeerPropertiesRequestV1::class, $capturedRequests[0]);
        $this->assertInstanceOf(SaslHandshakeRequestV1::class, $capturedRequests[1]);
        $this->assertInstanceOf(SaslAuthenticateRequestV1::class, $capturedRequests[2]);
        $this->assertInstanceOf(TuneResponseV1::class, $capturedRequests[3]);
        $this->assertInstanceOf(OpenRequestV1::class, $capturedRequests[4]);
        $this->assertInstanceOf(ExchangeCommandVersionsRequestV1::class, $capturedRequests[5]);

        unset($connection);
    }

    public function testCreateExchangesCommandVersionsAndStoresThem(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                new ExchangeCommandVersionsResponseV1([
                    new CommandVersion(KeyEnum::PUBLISH->value, 1, 2),
                    new CommandVersion(KeyEnum::CREATE->value, 1, 1),
                ]),
            );

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        /** @var array<int, CommandVersion>|null $storedVersions */
        $storedVersions = null;
        $streamConnection->expects($this->once())
            ->method('setCommandVersions')
            ->willReturnCallback(function (array $versions) use (&$storedVersions): void {
                $storedVersions = $versions;
            });

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertIsArray($storedVersions);
        $this->assertArrayHasKey(KeyEnum::PUBLISH->value, $storedVersions);
        $this->assertSame(2, $storedVersions[KeyEnum::PUBLISH->value]->getMaxVersion());
        $this->assertArrayHasKey(KeyEnum::CREATE->value, $storedVersions);

        // The advertised request must list what the client implements, with
        // Publish as the only multi-version command.
        $exchangeRequests = array_values(array_filter(
            $capturedRequests,
            fn(object $r): bool => $r instanceof ExchangeCommandVersionsRequestV1
        ));
        $this->assertCount(1, $exchangeRequests);
        $exchangeRequest = $exchangeRequests[0];
        $this->assertInstanceOf(ExchangeCommandVersionsRequestV1::class, $exchangeRequest);

        $advertised = [];
        foreach ($exchangeRequest->getCommands() as $command) {
            $advertised[$command->getKey()] = [$command->getMinVersion(), $command->getMaxVersion()];
        }
        $this->assertSame([1, 2], $advertised[KeyEnum::PUBLISH->value] ?? null);
        $this->assertSame([1, 1], $advertised[KeyEnum::DELIVER->value] ?? null);
        $this->assertSame([1, 1], $advertised[KeyEnum::CREATE->value] ?? null);

        unset($connection);
    }

    public function testSupportsCommandVersionDelegatesToTheStreamConnection(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                $this->commandVersionsResponse(),
            );
        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');
        $streamConnection->method('setCommandVersions');

        $range = new CommandVersion(KeyEnum::PUBLISH->value, 1, 2);
        $streamConnection->method('getCommandVersions')
            ->willReturn([KeyEnum::PUBLISH->value => $range]);
        $streamConnection->method('supportsCommandVersion')
            ->willReturnCallback(
                fn(KeyEnum $key, int $version): bool => $key === KeyEnum::PUBLISH && $version === 2
            );

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertSame([KeyEnum::PUBLISH->value => $range], $connection->getSupportedCommandVersions());
        $this->assertTrue($connection->supportsCommandVersion(KeyEnum::PUBLISH, 2));
        $this->assertFalse($connection->supportsCommandVersion(KeyEnum::PUBLISH, 1));

        unset($connection);
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function commandVersionFallbackFailures(): array
    {
        return [
            'timeout' => [new TimeoutException('Read timeout')],
            'protocol rejection' => [new ProtocolException('ExchangeCommandVersions rejected')],
            'deserialization failure' => [new DeserializationException('Malformed reply')],
        ];
    }

    /**
     * A broker that does not answer, rejects, or garbles the optional
     * ExchangeCommandVersions step must not fail connection setup; the
     * connection stores an empty map, which {@see StreamConnection} reads as
     * the v1 baseline for every command.
     *
     * @dataProvider commandVersionFallbackFailures
     */
    public function testCreateFallsBackToV1WhenCommandVersionExchangeFails(\Throwable $failure): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use ($failure): object {
                static $call = 0;
                $call++;
                if ($call === 6) {
                    throw $failure;
                }

                return match ($call) {
                    1 => $this->peerPropertiesResponse(),
                    2 => new SaslHandshakeResponseV1(['PLAIN']),
                    3 => new SaslAuthenticateResponseV1(),
                    4 => new TuneRequestV1(131072, 60),
                    5 => new OpenResponseV1(),
                    default => throw new \RuntimeException('readMessage() called more times than expected'),
                };
            });
        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $streamConnection->expects($this->once())
            ->method('setCommandVersions')
            ->with([]);

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertSame([], $connection->getSupportedCommandVersions());

        unset($connection);
    }

    public function testCreateFallsBackToV1WhenCommandVersionExchangeRepliesWithAnotherCommand(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                // Not an ExchangeCommandVersions response: treat it as a broker
                // that does not understand the command.
                new OpenResponseV1(),
            );
        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $streamConnection->expects($this->once())
            ->method('setCommandVersions')
            ->with([]);

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertSame([], $connection->getSupportedCommandVersions());

        unset($connection);
    }

    /**
     * A canned ExchangeCommandVersions reply advertising Publish v1..v2 — the
     * command the negotiation actually gates today. Shared by the handshake
     * tests so the extra handshake step reads as one entry, not five.
     */
    private function commandVersionsResponse(): ExchangeCommandVersionsResponseV1
    {
        return new ExchangeCommandVersionsResponseV1([
            new CommandVersion(KeyEnum::PUBLISH->value, 1, 2),
        ]);
    }

    /**
     * PeerProperties reply carrying the broker's `version` property, which the
     * ExchangeCommandVersions version gate reads. A null $version omits the
     * property, simulating a broker that does not report one.
     */
    private function peerPropertiesResponse(?string $version = '4.0.0'): PeerPropertiesResponseV1
    {
        if ($version === null) {
            return new PeerPropertiesResponseV1();
        }

        return new PeerPropertiesResponseV1(new KeyValue('version', $version));
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function brokerVersionGate(): array
    {
        return [
            'pre-3.11 minor' => ['3.10.2', false],
            'pre-3.11 major' => ['2.9.0', false],
            'exactly 3.11' => ['3.11.0', true],
            '3.11 without patch' => ['3.11', true],
            'modern 4.x' => ['4.1.2', true],
            'missing version' => [null, false],
            'garbage version' => ['not-a-version', false],
        ];
    }

    /**
     * The exchange is only sent to a broker that implements it (RabbitMQ >=
     * 3.11). Older brokers may answer an unknown frame by closing the
     * connection, so the gate is what makes the fallback graceful (R1-1).
     *
     * @dataProvider brokerVersionGate
     */
    public function testCreateOnlyExchangesCommandVersionsOnBroker311OrNewer(
        ?string $version,
        bool $expectExchange
    ): void {
        $streamConnection = $this->createMock(StreamConnection::class);

        $responses = [
            $this->peerPropertiesResponse($version),
            new SaslHandshakeResponseV1(['PLAIN']),
            new SaslAuthenticateResponseV1(),
            new TuneRequestV1(131072, 60),
            new OpenResponseV1(),
        ];
        if ($expectExchange) {
            $responses[] = $this->commandVersionsResponse();
        }
        $streamConnection->method('readMessage')->willReturnOnConsecutiveCalls(...$responses);
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        /** @var array<int, CommandVersion>|null $stored */
        $stored = null;
        $streamConnection->method('setCommandVersions')
            ->willReturnCallback(function (array $versions) use (&$stored): void {
                $stored = $versions;
            });

        $connection = Connection::create(streamConnection: $streamConnection);

        $exchangeRequests = array_filter(
            $capturedRequests,
            fn(object $r): bool => $r instanceof ExchangeCommandVersionsRequestV1
        );

        if ($expectExchange) {
            $this->assertCount(1, $exchangeRequests);
            $this->assertNotSame([], $stored);
        } else {
            $this->assertCount(0, $exchangeRequests);
            $this->assertSame([], $stored);
        }

        unset($connection);
    }

    public function testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(function (): object {
                static $call = 0;
                $call++;
                if ($call === 6) {
                    throw new TimeoutException('Read timeout');
                }

                return match ($call) {
                    1 => $this->peerPropertiesResponse(),
                    2 => new SaslHandshakeResponseV1(['PLAIN']),
                    3 => new SaslAuthenticateResponseV1(),
                    4 => new TuneRequestV1(131072, 60),
                    5 => new OpenResponseV1(),
                    default => throw new \RuntimeException('readMessage() called more times than expected'),
                };
            });
        // sendMessage is mocked, so the request keeps its default correlation
        // id of 0; the production path assigns a real one before reading.
        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');
        $streamConnection->expects($this->once())->method('setCommandVersions')->with([]);
        $streamConnection->expects($this->once())->method('abandonCorrelation')->with(0);

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertSame([], $connection->getSupportedCommandVersions());

        unset($connection);
    }

    public function testCreateStoresEmptyMapWhenTheBrokerRepliesWithNoCommands(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                // A valid reply that simply lists no commands: not an error, the
                // connection stays on the v1 baseline.
                new ExchangeCommandVersionsResponseV1([]),
            );
        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');
        $streamConnection->expects($this->once())->method('setCommandVersions')->with([]);

        $connection = Connection::create(streamConnection: $streamConnection);

        $this->assertSame([], $connection->getSupportedCommandVersions());

        unset($connection);
    }

    public function testCreateStoresLastRangeWhenTheReplyRepeatsACommandKey(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                $this->peerPropertiesResponse(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
                new ExchangeCommandVersionsResponseV1([
                    new CommandVersion(KeyEnum::PUBLISH->value, 1, 1),
                    new CommandVersion(KeyEnum::PUBLISH->value, 1, 2),
                    new CommandVersion(KeyEnum::CREATE->value, 1, 1),
                ]),
            );
        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        /** @var array<int, CommandVersion>|null $stored */
        $stored = null;
        $streamConnection->method('setCommandVersions')
            ->willReturnCallback(function (array $versions) use (&$stored): void {
                $stored = $versions;
            });

        $connection = Connection::create(streamConnection: $streamConnection);

        // The map is keyed by command key, so the last range for a repeated key
        // wins (pinned so a future change to that merge is deliberate).
        $this->assertIsArray($stored);
        $this->assertSame(2, $stored[KeyEnum::PUBLISH->value]->getMaxVersion());
        $this->assertArrayHasKey(KeyEnum::CREATE->value, $stored);

        unset($connection);
    }

    /**
     * Drift guard (R1-3): clientCommandVersions() is a hand-kept list next to
     * the request classes' getKey()/getVersion(). This fails if a request class
     * is added, or a version bumped, without updating the advertised ranges.
     */
    public function testClientCommandVersionsCoversEveryClientInitiatedRequestClass(): void
    {
        $method = new \ReflectionMethod(Connection::class, 'clientCommandVersions');
        /** @var list<CommandVersion> $ranges */
        $ranges = $method->invoke(null);

        $advertised = [];
        foreach ($ranges as $range) {
            $advertised[$range->getKey()] = $range;
        }

        // Handshake/connection commands are deliberately not advertised: the
        // broker does not negotiate them either, and the v1 baseline covers them.
        $handshakeKeys = [
            KeyEnum::PEER_PROPERTIES->value,
            KeyEnum::SASL_HANDSHAKE->value,
            KeyEnum::SASL_AUTHENTICATE->value,
            KeyEnum::TUNE->value,
            KeyEnum::OPEN->value,
            KeyEnum::CLOSE->value,
            KeyEnum::HEARTBEAT->value,
        ];

        $files = glob(dirname(__DIR__, 2) . '/src/Request/*.php');
        $this->assertIsArray($files);

        $scanned = 0;
        foreach ($files as $file) {
            /** @var class-string<KeyVersionInterface> $class */
            $class = 'CrazyGoat\\RabbitStream\\Request\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, KeyVersionInterface::class)) {
                continue;
            }

            $key = $class::getKey();
            // Response-direction keys (ConsumerUpdateReply) and handshake
            // commands are not negotiated.
            if (($key & 0x8000) !== 0) {
                continue;
            }
            if (in_array($key, $handshakeKeys, true)) {
                continue;
            }

            $scanned++;
            $this->assertArrayHasKey(
                $key,
                $advertised,
                $class . ' is not advertised by clientCommandVersions()'
            );
            $this->assertSame(
                1,
                $advertised[$key]->getMinVersion(),
                $class . ' must be advertised from v1'
            );
            $this->assertGreaterThanOrEqual(
                $class::getVersion(),
                $advertised[$key]->getMaxVersion(),
                $class . ' v' . $class::getVersion() . ' exceeds the advertised max version'
            );
        }

        $this->assertGreaterThan(0, $scanned, 'Drift guard scanned no request classes');
    }
}
