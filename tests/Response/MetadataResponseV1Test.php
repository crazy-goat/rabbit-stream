<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use PHPUnit\Framework\TestCase;

class MetadataResponseV1Test extends TestCase
{
    public function testDeserializesWithBrokerAndMetadata(): void
    {
        // Build response buffer
        $raw = pack('n', 0x800f)           // key (METADATA_RESPONSE)
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('N', 1)                  // 1 broker
            . pack('n', 1)                  // broker reference
            . pack('n', 9) . 'localhost'    // broker host
            . pack('N', 5552)               // broker port
            . pack('N', 1)                  // 1 stream metadata
            . pack('n', 9) . 'my-stream'    // stream name
            . pack('n', 0x0001)             // response code (OK)
            . pack('n', 1)                  // leader reference
            . pack('N', 0);                 // 0 replicas

        $response = MetadataResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(MetadataResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());

        $brokers = $response->getBrokers();
        $this->assertCount(1, $brokers);
        $this->assertSame(1, $brokers[0]->getReference());
        $this->assertSame('localhost', $brokers[0]->getHost());
        $this->assertSame(5552, $brokers[0]->getPort());

        $metadata = $response->getStreamMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame('my-stream', $metadata[0]->getStreamName());
        $this->assertSame(0x0001, $metadata[0]->getResponseCode());
        $this->assertSame(1, $metadata[0]->getLeaderReference());
        $this->assertCount(0, $metadata[0]->getReplicasReferences());
    }

    public function testDeserializesWithMultipleBrokersAndStreams(): void
    {
        // Build response buffer with multiple brokers and streams
        $raw = pack('n', 0x800f)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('N', 2)                  // 2 brokers
            . pack('n', 1)                  // broker 1 reference
            . pack('n', 7) . 'broker1'      // broker 1 host
            . pack('N', 5552)               // broker 1 port
            . pack('n', 2)                  // broker 2 reference
            . pack('n', 7) . 'broker2'      // broker 2 host
            . pack('N', 5553)               // broker 2 port
            . pack('N', 1)                  // 1 stream metadata
            . pack('n', 9) . 'my-stream'    // stream name
            . pack('n', 0x0001)             // response code (OK)
            . pack('n', 1)                  // leader reference (broker 1)
            . pack('N', 1)                  // 1 replica
            . pack('n', 2);                 // replica reference (broker 2)

        $response = MetadataResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(MetadataResponseV1::class, $response);

        $brokers = $response->getBrokers();
        $this->assertCount(2, $brokers);
        $this->assertSame(1, $brokers[0]->getReference());
        $this->assertSame('broker1', $brokers[0]->getHost());
        $this->assertSame(2, $brokers[1]->getReference());
        $this->assertSame('broker2', $brokers[1]->getHost());

        $metadata = $response->getStreamMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame('my-stream', $metadata[0]->getStreamName());
        $this->assertSame(1, $metadata[0]->getLeaderReference());
        $this->assertCount(1, $metadata[0]->getReplicasReferences());
        $this->assertSame(2, $metadata[0]->getReplicasReferences()[0]);
    }

    public function testDeserializesWithEmptyResponse(): void
    {
        $raw = pack('n', 0x800f)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('N', 0)                  // 0 brokers
            . pack('N', 0);                 // 0 stream metadata

        $response = MetadataResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(MetadataResponseV1::class, $response);
        $this->assertCount(0, $response->getBrokers());
        $this->assertCount(0, $response->getStreamMetadata());
    }
}
