<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateQueryV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\CreditResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeletePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;
use PHPUnit\Framework\TestCase;

class FromArrayTest extends TestCase
{
    public function testSaslAuthenticateResponseFromArray(): void
    {
        $response = SaslAuthenticateResponseV1::fromArray(['correlationId' => 5]);
        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $response);
        $this->assertSame(5, $response->getCorrelationId());
    }

    public function testCloseResponseFromArray(): void
    {
        $response = CloseResponseV1::fromArray(['correlationId' => 10]);
        $this->assertSame(10, $response->getCorrelationId());
    }

    public function testCreateResponseFromArray(): void
    {
        $response = CreateResponseV1::fromArray(['correlationId' => 1]);
        $this->assertSame(1, $response->getCorrelationId());
    }

    public function testDeleteStreamResponseFromArray(): void
    {
        $response = DeleteStreamResponseV1::fromArray(['correlationId' => 2]);
        $this->assertSame(2, $response->getCorrelationId());
    }

    public function testDeclarePublisherResponseFromArray(): void
    {
        $response = DeclarePublisherResponseV1::fromArray(['correlationId' => 3]);
        $this->assertSame(3, $response->getCorrelationId());
    }

    public function testDeletePublisherResponseFromArray(): void
    {
        $response = DeletePublisherResponseV1::fromArray(['correlationId' => 4]);
        $this->assertSame(4, $response->getCorrelationId());
    }

    public function testSubscribeResponseFromArray(): void
    {
        $response = SubscribeResponseV1::fromArray(['correlationId' => 6]);
        $this->assertSame(6, $response->getCorrelationId());
    }

    public function testUnsubscribeResponseFromArray(): void
    {
        $response = UnsubscribeResponseV1::fromArray(['correlationId' => 7]);
        $this->assertSame(7, $response->getCorrelationId());
    }

    public function testCreateSuperStreamResponseFromArray(): void
    {
        $response = CreateSuperStreamResponseV1::fromArray(['correlationId' => 8]);
        $this->assertSame(8, $response->getCorrelationId());
    }

    public function testDeleteSuperStreamResponseFromArray(): void
    {
        $response = DeleteSuperStreamResponseV1::fromArray(['correlationId' => 9]);
        $this->assertSame(9, $response->getCorrelationId());
    }

    public function testSaslHandshakeResponseFromArray(): void
    {
        $response = SaslHandshakeResponseV1::fromArray([
            'correlationId' => 1,
            'mechanisms' => ['PLAIN', 'AMQPLAIN'],
        ]);
        $this->assertSame(1, $response->getCorrelationId());
    }

    public function testOpenResponseFromArray(): void
    {
        $response = OpenResponseV1::fromArray([
            'correlationId' => 2,
            'connectionProperties' => [
                ['key' => 'product', 'value' => 'RabbitMQ'],
            ],
        ]);
        $this->assertSame(2, $response->getCorrelationId());
        $this->assertInstanceOf(OpenResponseV1::class, $response);
    }

    public function testPeerPropertiesResponseFromArray(): void
    {
        $response = PeerPropertiesResponseV1::fromArray([
            'correlationId' => 3,
            'properties' => [
                ['key' => 'product', 'value' => 'RabbitMQ'],
                ['key' => 'version', 'value' => '3.12'],
            ],
        ]);
        $this->assertSame(3, $response->getCorrelationId());
        $this->assertCount(2, $response->getPeerProperty());
    }

    public function testQueryOffsetResponseFromArray(): void
    {
        $response = QueryOffsetResponseV1::fromArray(['correlationId' => 4, 'offset' => 12345]);
        $this->assertSame(4, $response->getCorrelationId());
        $this->assertSame(12345, $response->getOffset());
    }

    public function testQueryPublisherSequenceResponseFromArray(): void
    {
        $response = QueryPublisherSequenceResponseV1::fromArray(['correlationId' => 5, 'sequence' => 99]);
        $this->assertSame(5, $response->getCorrelationId());
        $this->assertSame(99, $response->getSequence());
    }

    public function testStreamStatsResponseFromArray(): void
    {
        $response = StreamStatsResponseV1::fromArray([
            'correlationId' => 6,
            'stats' => [['key' => 'messages', 'value' => 1000]],
        ]);
        $this->assertSame(6, $response->getCorrelationId());
        $this->assertCount(1, $response->getStats());
        $this->assertSame('messages', $response->getStats()[0]->getKey());
        $this->assertSame(1000, $response->getStats()[0]->getValue());
    }

    public function testMetadataResponseFromArray(): void
    {
        $response = MetadataResponseV1::fromArray([
            'correlationId' => 7,
            'brokers' => [['reference' => 1, 'host' => 'localhost', 'port' => 5672]],
            'streamMetadata' => [
                ['stream' => 'my-stream', 'responseCode' => 1, 'leaderReference' => 1, 'replicasReferences' => []],
            ],
        ]);
        $this->assertSame(7, $response->getCorrelationId());
        $this->assertCount(1, $response->getBrokers());
        $this->assertCount(1, $response->getStreamMetadata());
    }

    public function testExchangeCommandVersionsResponseFromArray(): void
    {
        $response = ExchangeCommandVersionsResponseV1::fromArray([
            'correlationId' => 8,
            'commands' => [['key' => 1, 'minVersion' => 1, 'maxVersion' => 2]],
        ]);
        $this->assertSame(8, $response->getCorrelationId());
        $this->assertCount(1, $response->getCommands());
        $this->assertSame(1, $response->getCommands()[0]->getKey());
    }

    public function testRouteResponseFromArray(): void
    {
        $response = RouteResponseV1::fromArray([
            'correlationId' => 9,
            'streams' => ['stream1', 'stream2'],
        ]);
        $this->assertSame(9, $response->getCorrelationId());
        $this->assertSame(['stream1', 'stream2'], $response->getStreams());
    }

    public function testPartitionsResponseFromArray(): void
    {
        $response = PartitionsResponseV1::fromArray([
            'correlationId' => 10,
            'streams' => ['partition-0', 'partition-1'],
        ]);
        $this->assertSame(10, $response->getCorrelationId());
        $this->assertSame(['partition-0', 'partition-1'], $response->getStreams());
    }

    public function testResolveOffsetSpecResponseFromArray(): void
    {
        $response = ResolveOffsetSpecResponseV1::fromArray(['correlationId' => 11, 'offset' => 500]);
        $this->assertSame(11, $response->getCorrelationId());
        $this->assertSame(500, $response->getOffset());
    }

    public function testPublishConfirmResponseFromArray(): void
    {
        $response = PublishConfirmResponseV1::fromArray([
            'publisherId' => 1,
            'publishingIds' => [10, 11, 12],
        ]);
        $this->assertSame(1, $response->getPublisherId());
        $this->assertSame([10, 11, 12], $response->getPublishingIds());
    }

    public function testPublishErrorResponseFromArray(): void
    {
        $response = PublishErrorResponseV1::fromArray([
            'publisherId' => 2,
            'errors' => [['publishingId' => 5, 'code' => 2]],
        ]);
        $this->assertSame(2, $response->getPublisherId());
        $this->assertCount(1, $response->getErrors());
        $this->assertSame(5, $response->getErrors()[0]->getPublishingId());
        $this->assertSame(2, $response->getErrors()[0]->getCode());
    }

    public function testDeliverResponseFromArray(): void
    {
        $response = DeliverResponseV1::fromArray(['subscriptionId' => 3, 'chunkBytes' => 'raw-bytes']);
        $this->assertSame(3, $response->getSubscriptionId());
        $this->assertSame('raw-bytes', $response->getChunkBytes());
    }

    public function testMetadataUpdateResponseFromArray(): void
    {
        $response = MetadataUpdateResponseV1::fromArray(['code' => 1, 'stream' => 'my-stream']);
        $this->assertSame(1, $response->getCode());
        $this->assertSame('my-stream', $response->getStream());
    }

    public function testCreditResponseFromArray(): void
    {
        $response = CreditResponseV1::fromArray(['responseCode' => 1, 'subscriptionId' => 5]);
        $this->assertSame(1, $response->getResponseCode());
        $this->assertSame(5, $response->getSubscriptionId());
    }

    public function testConsumerUpdateQueryFromArray(): void
    {
        $response = ConsumerUpdateQueryV1::fromArray([
            'correlationId' => 12,
            'subscriptionId' => 3,
            'active' => true,
        ]);
        $this->assertSame(12, $response->getCorrelationId());
        $this->assertSame(3, $response->getSubscriptionId());
        $this->assertTrue($response->isActive());
    }

    public function testTuneResponseFromArray(): void
    {
        $response = TuneResponseV1::fromArray(['frameMax' => 131072, 'heartbeat' => 60]);
        $this->assertInstanceOf(TuneResponseV1::class, $response);
    }
}
