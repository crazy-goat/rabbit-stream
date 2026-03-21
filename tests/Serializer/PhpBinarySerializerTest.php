<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Serializer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeletePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;
use CrazyGoat\RabbitStream\ResponseBuilder;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;
use CrazyGoat\RabbitStream\VO\KeyValue;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class PhpBinarySerializerTest extends TestCase
{
    private PhpBinarySerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpBinarySerializer();
    }

    public function testSerializeReturnsSameBytesAsToStreamBuffer(): void
    {
        $request = new SaslHandshakeRequestV1();
        $request->withCorrelationId(1);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithPeerPropertiesRequest(): void
    {
        $request = new PeerPropertiesToStreamBufferV1(
            new KeyValue('product', 'test'),
            new KeyValue('version', '1.0')
        );
        $request->withCorrelationId(42);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithSaslAuthenticateRequest(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'user', 'pass');
        $request->withCorrelationId(99);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithTuneRequest(): void
    {
        $request = new TuneRequestV1(1000, 50000);
        // TuneRequestV1 does not implement CorrelationInterface

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithOpenRequest(): void
    {
        $request = new OpenRequestV1('/');
        $request->withCorrelationId(3);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithCreateRequest(): void
    {
        $request = new CreateRequestV1('test-stream');
        $request->withCorrelationId(5);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithDeleteStreamRequest(): void
    {
        $request = new DeleteStreamRequestV1('test-stream');
        $request->withCorrelationId(8);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithMetadataRequest(): void
    {
        $request = new MetadataRequestV1(['stream1', 'stream2']);
        $request->withCorrelationId(10);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithDeclarePublisherRequest(): void
    {
        $request = new DeclarePublisherRequestV1(1, 'test-publisher', 'test-stream');
        $request->withCorrelationId(12);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithDeletePublisherRequest(): void
    {
        $request = new DeletePublisherRequestV1(1);
        $request->withCorrelationId(15);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithSubscribeRequest(): void
    {
        $request = new SubscribeRequestV1(1, 'test-stream', OffsetSpec::next(), 10);
        $request->withCorrelationId(20);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithUnsubscribeRequest(): void
    {
        $request = new UnsubscribeRequestV1(1);
        $request->withCorrelationId(25);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithCloseRequest(): void
    {
        $request = new CloseRequestV1(0, 'Normal close');
        $request->withCorrelationId(30);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithHeartbeatRequest(): void
    {
        $request = new HeartbeatRequestV1();
        // HeartbeatRequestV1 does not implement CorrelationInterface

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithQueryOffsetRequest(): void
    {
        $request = new QueryOffsetRequestV1('reference', 'test-stream');
        $request->withCorrelationId(40);

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeWithStoreOffsetRequest(): void
    {
        $request = new StoreOffsetRequestV1('reference', 'test-stream', 12345);
        // StoreOffsetRequestV1 does not implement CorrelationInterface

        $serialized = $this->serializer->serialize($request);
        $expected = $request->toStreamBuffer()->getContents();

        $this->assertSame($expected, $serialized);
    }

    public function testSerializeThrowsExceptionForNonToStreamBufferInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request must implement ToStreamBufferInterface');

        $this->serializer->serialize(new \stdClass());
    }

    public function testDeserializeReturnsSameObjectAsResponseBuilder(): void
    {
        $raw = pack('n', 0x8012)            // key (SaslHandshakeResponse)
            . pack('n', 1)                  // version
            . pack('N', 1)                  // correlationId
            . pack('n', 0x0001)             // responseCode OK
            . pack('N', 2)                  // array length
            . pack('n', 5) . 'PLAIN'        // mechanism 1
            . pack('n', 9) . 'AMQPLAIN';   // mechanism 2

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $deserialized);
        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithPeerPropertiesResponse(): void
    {
        $raw = pack('n', 0x8011)            // key (PeerPropertiesResponse)
            . pack('n', 1)                  // version
            . pack('N', 2)                  // correlationId
            . pack('n', 0x0001)             // responseCode OK
            . pack('N', 1)                  // array length
            . pack('n', 7) . 'product'      // key
            . pack('n', 4) . 'test';        // value

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $deserialized);
        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithSaslAuthenticateResponse(): void
    {
        $raw = pack('n', 0x8013)            // key (SaslAuthenticateResponse)
            . pack('n', 1)                  // version
            . pack('N', 3)                  // correlationId
            . pack('n', 0x0001)             // responseCode OK
            . pack('n', 4) . 'data';        // sasl data

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $deserialized);
        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithOpenResponse(): void
    {
        $raw = pack('n', 0x8015)            // key (OpenResponse)
            . pack('n', 1)                  // version
            . pack('N', 4)                  // correlationId
            . pack('n', 0x0001)             // responseCode OK
            . pack('N', 0);                 // empty properties array

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(OpenResponseV1::class, $deserialized);
        $this->assertInstanceOf(OpenResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithCreateResponse(): void
    {
        $raw = pack('n', 0x800d)            // key (CreateResponse)
            . pack('n', 1)                  // version
            . pack('N', 5)                  // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CreateResponseV1::class, $deserialized);
        $this->assertInstanceOf(CreateResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithDeleteStreamResponse(): void
    {
        $raw = pack('n', 0x800e)            // key (DeleteResponse)
            . pack('n', 1)                  // version
            . pack('N', 8)                  // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeleteStreamResponseV1::class, $deserialized);
        $this->assertInstanceOf(DeleteStreamResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithMetadataResponse(): void
    {
        // MetadataResponse has a complex format: brokers array + stream metadata array
        $raw = pack('n', 0x800f)            // key (MetadataResponse)
            . pack('n', 1)                  // version
            . pack('N', 10)                 // correlationId
            . pack('N', 1)                  // 1 broker
            . pack('n', 1)                  // broker reference
            . pack('n', 9) . '127.0.0.1'    // broker host
            . pack('N', 5552)               // broker port
            . pack('N', 1)                  // 1 stream metadata
            . pack('n', 11) . 'test-stream' // stream name
            . pack('n', 0x0001)             // response code OK
            . pack('n', 1)                  // leader reference
            . pack('N', 0);                 // 0 replicas

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(MetadataResponseV1::class, $deserialized);
        $this->assertInstanceOf(MetadataResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithDeclarePublisherResponse(): void
    {
        $raw = pack('n', 0x8001)            // key (DeclarePublisherResponse)
            . pack('n', 1)                  // version
            . pack('N', 12)                 // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $deserialized);
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithDeletePublisherResponse(): void
    {
        $raw = pack('n', 0x8006)            // key (DeletePublisherResponse)
            . pack('n', 1)                  // version
            . pack('N', 15)                 // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeletePublisherResponseV1::class, $deserialized);
        $this->assertInstanceOf(DeletePublisherResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithSubscribeResponse(): void
    {
        $raw = pack('n', 0x8007)            // key (SubscribeResponse)
            . pack('n', 1)                  // version
            . pack('N', 20)                 // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SubscribeResponseV1::class, $deserialized);
        $this->assertInstanceOf(SubscribeResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithUnsubscribeResponse(): void
    {
        $raw = pack('n', 0x800c)            // key (UnsubscribeResponse)
            . pack('n', 1)                  // version
            . pack('N', 25)                 // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(UnsubscribeResponseV1::class, $deserialized);
        $this->assertInstanceOf(UnsubscribeResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }

    public function testDeserializeWithCloseResponse(): void
    {
        $raw = pack('n', 0x8016)            // key (CloseResponse)
            . pack('n', 1)                  // version
            . pack('N', 30)                 // correlationId
            . pack('n', 0x0001);            // responseCode OK

        $deserialized = $this->serializer->deserialize($raw);
        $expected = ResponseBuilder::fromResponseBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CloseResponseV1::class, $deserialized);
        $this->assertInstanceOf(CloseResponseV1::class, $expected);
        $this->assertSame($expected->getCorrelationId(), $deserialized->getCorrelationId());
    }
}
