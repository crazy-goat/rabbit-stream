<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\ConsumerUpdateReplyV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\VO\CommandVersion;
use CrazyGoat\RabbitStream\VO\KeyValue;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;
use PHPUnit\Framework\TestCase;

class ToArrayTest extends TestCase
{
    public function testSaslHandshakeRequestReturnsEmptyArray(): void
    {
        $request = new SaslHandshakeRequestV1();
        $this->assertSame([], $request->toArray());
    }

    public function testHeartbeatRequestReturnsEmptyArray(): void
    {
        $request = new HeartbeatRequestV1();
        $this->assertSame([], $request->toArray());
    }

    public function testOpenRequestReturnsVhost(): void
    {
        $request = new OpenRequestV1('/my-vhost');
        $this->assertSame(['vhost' => '/my-vhost'], $request->toArray());
    }

    public function testDeleteStreamRequestReturnsStream(): void
    {
        $request = new DeleteStreamRequestV1('my-stream');
        $this->assertSame(['stream' => 'my-stream'], $request->toArray());
    }

    public function testDeletePublisherRequestReturnsPublisherId(): void
    {
        $request = new DeletePublisherRequestV1(5);
        $this->assertSame(['publisherId' => 5], $request->toArray());
    }

    public function testUnsubscribeRequestReturnsSubscriptionId(): void
    {
        $request = new UnsubscribeRequestV1(3);
        $this->assertSame(['subscriptionId' => 3], $request->toArray());
    }

    public function testStreamStatsRequestReturnsStream(): void
    {
        $request = new StreamStatsRequestV1('stats-stream');
        $this->assertSame(['stream' => 'stats-stream'], $request->toArray());
    }

    public function testPartitionsRequestReturnsSuperStream(): void
    {
        $request = new PartitionsRequestV1('my-super-stream');
        $this->assertSame(['superStream' => 'my-super-stream'], $request->toArray());
    }

    public function testDeleteSuperStreamRequestReturnsName(): void
    {
        $request = new DeleteSuperStreamRequestV1('super-stream-name');
        $this->assertSame(['name' => 'super-stream-name'], $request->toArray());
    }

    public function testSaslAuthenticateRequestReturnsCredentials(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'user', 'pass');
        $this->assertSame([
            'mechanism' => 'PLAIN',
            'username' => 'user',
            'password' => '***',
        ], $request->toArray());
    }

    public function testTuneRequestReturnsFrameMaxAndHeartbeat(): void
    {
        $request = new TuneRequestV1(131072, 60);
        $this->assertSame(['frameMax' => 131072, 'heartbeat' => 60], $request->toArray());
    }

    public function testCloseRequestReturnsCodeAndReason(): void
    {
        $request = new CloseRequestV1(1, 'normal shutdown');
        $this->assertSame([
            'closingCode' => 1,
            'closingReason' => 'normal shutdown',
        ], $request->toArray());
    }

    public function testDeclarePublisherRequestReturnsAllFields(): void
    {
        $request = new DeclarePublisherRequestV1(1, 'my-ref', 'my-stream');
        $this->assertSame([
            'publisherId' => 1,
            'publisherReference' => 'my-ref',
            'stream' => 'my-stream',
        ], $request->toArray());
    }

    public function testDeclarePublisherRequestWithNullReference(): void
    {
        $request = new DeclarePublisherRequestV1(2, null, 'stream');
        $this->assertSame([
            'publisherId' => 2,
            'publisherReference' => null,
            'stream' => 'stream',
        ], $request->toArray());
    }

    public function testCreditRequestReturnsSubscriptionIdAndCredit(): void
    {
        $request = new CreditRequestV1(7, 100);
        $this->assertSame(['subscriptionId' => 7, 'credit' => 100], $request->toArray());
    }

    public function testStoreOffsetRequestReturnsAllFields(): void
    {
        $request = new StoreOffsetRequestV1('ref', 'stream', 42);
        $this->assertSame([
            'reference' => 'ref',
            'stream' => 'stream',
            'offset' => 42,
        ], $request->toArray());
    }

    public function testQueryOffsetRequestReturnsReferenceAndStream(): void
    {
        $request = new QueryOffsetRequestV1('ref', 'stream');
        $this->assertSame(['reference' => 'ref', 'stream' => 'stream'], $request->toArray());
    }

    public function testQueryPublisherSequenceRequestReturnsReferenceAndStream(): void
    {
        $request = new QueryPublisherSequenceRequestV1('ref', 'stream');
        $this->assertSame(['reference' => 'ref', 'stream' => 'stream'], $request->toArray());
    }

    public function testRouteRequestReturnsRoutingKeyAndSuperStream(): void
    {
        $request = new RouteRequestV1('key', 'super');
        $this->assertSame(['routingKey' => 'key', 'superStream' => 'super'], $request->toArray());
    }

    public function testResolveOffsetSpecRequestReturnsStreamAndOffsetSpec(): void
    {
        $request = new ResolveOffsetSpecRequestV1('stream', OffsetSpec::first());
        $array = $request->toArray();
        $this->assertSame('stream', $array['stream']);
        $this->assertSame(['type' => OffsetSpec::TYPE_FIRST, 'value' => null], $array['offsetSpec']);
    }

    public function testPeerPropertiesRequestReturnsProperties(): void
    {
        $request = new PeerPropertiesToStreamBufferV1(
            new KeyValue('product', 'test'),
            new KeyValue('version', '1.0')
        );
        $this->assertSame([
            'properties' => [
                ['key' => 'product', 'value' => 'test'],
                ['key' => 'version', 'value' => '1.0'],
            ],
        ], $request->toArray());
    }

    public function testCreateRequestReturnsStreamAndArguments(): void
    {
        $request = new CreateRequestV1('my-stream', ['max-length-bytes' => '1000000']);
        $this->assertSame([
            'stream' => 'my-stream',
            'arguments' => ['max-length-bytes' => '1000000'],
        ], $request->toArray());
    }

    public function testMetadataRequestReturnsStreams(): void
    {
        $request = new MetadataRequestV1(['stream1', 'stream2']);
        $this->assertSame(['streams' => ['stream1', 'stream2']], $request->toArray());
    }

    public function testPublishRequestV1ReturnsPublisherIdAndMessages(): void
    {
        $msg = new PublishedMessage(1, 'hello');
        $request = new PublishRequestV1(3, $msg);
        $array = $request->toArray();
        $this->assertSame(3, $array['publisherId']);
        $this->assertSame([['publishingId' => 1, 'data' => 'hello']], $array['messages']);
    }

    public function testPublishRequestV2ReturnsPublisherIdAndMessages(): void
    {
        $msg = new PublishedMessageV2(1, 'filter-val', 'hello');
        $request = new PublishRequestV2(4, $msg);
        $array = $request->toArray();
        $this->assertSame(4, $array['publisherId']);
        $this->assertSame([[
            'publishingId' => 1,
            'filterValue' => 'filter-val',
            'data' => 'hello',
        ]], $array['messages']);
    }

    public function testSubscribeRequestReturnsAllFields(): void
    {
        $request = new SubscribeRequestV1(1, 'stream', OffsetSpec::offset(100), 10);
        $array = $request->toArray();
        $this->assertSame(1, $array['subscriptionId']);
        $this->assertSame('stream', $array['stream']);
        $this->assertSame(['type' => OffsetSpec::TYPE_OFFSET, 'value' => 100], $array['offsetSpec']);
        $this->assertSame(10, $array['credit']);
    }

    public function testExchangeCommandVersionsRequestReturnsCommands(): void
    {
        $cv = new CommandVersion(1, 1, 1);
        $request = new ExchangeCommandVersionsRequestV1([$cv]);
        $this->assertSame([
            'commands' => [['key' => 1, 'minVersion' => 1, 'maxVersion' => 1]],
        ], $request->toArray());
    }

    public function testCreateSuperStreamRequestReturnsAllFields(): void
    {
        $request = new CreateSuperStreamRequestV1('super', ['p1', 'p2'], ['k1', 'k2'], ['arg' => 'val']);
        $this->assertSame([
            'name' => 'super',
            'partitions' => ['p1', 'p2'],
            'bindingKeys' => ['k1', 'k2'],
            'arguments' => ['arg' => 'val'],
        ], $request->toArray());
    }

    public function testConsumerUpdateReplyReturnsAllFields(): void
    {
        $request = new ConsumerUpdateReplyV1(10, 1, 4, 500);
        $this->assertSame([
            'correlationId' => 10,
            'responseCode' => 1,
            'offsetType' => 4,
            'offset' => 500,
        ], $request->toArray());
    }
}
