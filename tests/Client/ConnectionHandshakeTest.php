<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\StreamConnection;
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
                new PeerPropertiesResponseV1(),
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
                new PeerPropertiesResponseV1(),
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
                new PeerPropertiesResponseV1(),
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
                new PeerPropertiesResponseV1(),
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
                new PeerPropertiesResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(0, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(0xFFFFFFFF, 60),
                new OpenResponseV1(),
            );

        $streamConnection->method('sendMessage');

        $streamConnection->expects($this->once())
            ->method('setMaxFrameSize')
            ->with(StreamConnection::DEFAULT_MAX_FRAME_SIZE);

        $streamConnection->expects($this->once())
            ->method('setOutgoingMaxFrameSize')
            ->with(0xFFFFFFFF);

        $streamConnection->method('close');

        $connection = Connection::create(streamConnection: $streamConnection);

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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(0xFFFFFFFF, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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

    public function testCreateSetsOutgoingMaxFrameSizeToNegotiatedValue(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
            );

        $streamConnection->method('sendMessage');
        $streamConnection->method('setMaxFrameSize');
        $streamConnection->method('close');

        $streamConnection->expects($this->once())
            ->method('setOutgoingMaxFrameSize')
            ->with(131072);

        $connection = Connection::create(streamConnection: $streamConnection);

        unset($connection);
    }

    public function testCreatePassesVhostToOpenRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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
                new PeerPropertiesResponseV1(),
                new SaslHandshakeResponseV1(['PLAIN']),
                new SaslAuthenticateResponseV1(),
                new TuneRequestV1(131072, 60),
                new OpenResponseV1(),
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
        $this->assertCount(5, $capturedRequests);
        $this->assertInstanceOf(PeerPropertiesRequestV1::class, $capturedRequests[0]);
        $this->assertInstanceOf(SaslHandshakeRequestV1::class, $capturedRequests[1]);
        $this->assertInstanceOf(SaslAuthenticateRequestV1::class, $capturedRequests[2]);
        $this->assertInstanceOf(TuneResponseV1::class, $capturedRequests[3]);
        $this->assertInstanceOf(OpenRequestV1::class, $capturedRequests[4]);

        unset($connection);
    }
}
