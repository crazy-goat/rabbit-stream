<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
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
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;
use CrazyGoat\RabbitStream\ResponseBuilder;
use PHPUnit\Framework\TestCase;

class ResponseBuilderTest extends TestCase
{
    public function testDispatchesV1ResponseCorrectly(): void
    {
        $raw = pack('n', 0x800d)    // key: CREATE_RESPONSE
            . pack('n', 1)          // version: 1
            . pack('N', 42)         // correlationId
            . pack('n', 0x0001);   // responseCode: OK

        $buffer = new ReadBuffer($raw);
        $result = ResponseBuilder::fromResponseBuffer($buffer);

        $this->assertInstanceOf(CreateResponseV1::class, $result);
        $this->assertSame(42, $result->getCorrelationId());
    }

    public function testDispatchesV2DeliverCorrectly(): void
    {
        // Deliver is a server-push frame with no correlationId/responseCode
        // Format: key (uint16) + version (uint16) + subscriptionId (uint8) + chunkData
        $raw = pack('n', 0x0008)    // key: DELIVER
            . pack('n', 2)          // version: 2
            . pack('C', 1)          // subscriptionId (uint8)
            . pack('J', 0)          // committedChunkId (uint64) for V2
            . pack('N', 0);         // empty chunk data

        $buffer = new ReadBuffer($raw);
        $result = ResponseBuilder::fromResponseBuffer($buffer);

        $this->assertInstanceOf(DeliverResponseV1::class, $result);
        $this->assertSame(1, $result->getSubscriptionId());
    }

    public function testThrowsOnUnknownCommand(): void
    {
        // Use CONSUMER_UPDATE_RESPONSE (0x801a) which is a valid enum but not in getV1() match
        $raw = pack('n', 0x801a)    // key: CONSUMER_UPDATE_RESPONSE (not handled in match)
            . pack('n', 1)          // version: 1
            . pack('N', 1)         // correlationId
            . pack('n', 0x0001);   // responseCode: OK

        $buffer = new ReadBuffer($raw);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected command');
        ResponseBuilder::fromResponseBuffer($buffer);
    }

    public function testThrowsOnUnknownVersion(): void
    {
        $raw = pack('n', 0x800d)    // key: CREATE_RESPONSE
            . pack('n', 3)          // version: 3 (unsupported)
            . pack('N', 1)         // correlationId
            . pack('n', 0x0001);   // responseCode: OK

        $buffer = new ReadBuffer($raw);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected protocol version: 3');
        ResponseBuilder::fromResponseBuffer($buffer);
    }

    public function testKeyEnumFromStreamCodeWithMappedCode(): void
    {
        $result = KeyEnum::fromStreamCode(0x800d);
        $this->assertSame(KeyEnum::CREATE_RESPONSE, $result);
    }

    public function testKeyEnumFromStreamCodeWithUnmappedCode(): void
    {
        // 0x000d is CREATE (request), not mapped directly
        // fromStreamCode should subtract 0x8000 and find CREATE_RESPONSE
        $result = KeyEnum::fromStreamCode(0x000d);
        $this->assertSame(KeyEnum::CREATE, $result);
    }

    /**
     * @dataProvider v1ResponseProvider
     * @param class-string<object> $expectedClass
     */
    public function testAllV1ResponsesAreDispatched(int $key, string $expectedClass, string $frameData): void
    {
        $raw = pack('n', $key)
            . pack('n', 1)          // version: 1
            . $frameData;

        $buffer = new ReadBuffer($raw);
        $result = ResponseBuilder::fromResponseBuffer($buffer);

        $this->assertInstanceOf($expectedClass, $result);
    }

    /**
     * @return array<string, array{0: int, 1: class-string<object>, 2: string}>
     */
    public static function v1ResponseProvider(): array
    {
        return [
            'DECLARE_PUBLISHER_RESPONSE' => [
                0x8001,
                DeclarePublisherResponseV1::class,
                pack('N', 1) . pack('n', 0x0001), // correlationId + responseCode OK
            ],
            'DELETE_PUBLISHER_RESPONSE' => [
                0x8006,
                DeletePublisherResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'SUBSCRIBE_RESPONSE' => [
                0x8007,
                SubscribeResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'UNSUBSCRIBE_RESPONSE' => [
                0x800c,
                UnsubscribeResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'CREATE_RESPONSE' => [
                0x800d,
                CreateResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'DELETE_RESPONSE' => [
                0x800e,
                DeleteStreamResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'PUBLISH_CONFIRM' => [
                0x0003,
                PublishConfirmResponseV1::class,
                // publisherId (uint8) + publishingIds count (uint32) + empty array
                pack('C', 1) . pack('N', 0),
            ],
            'PUBLISH_ERROR' => [
                0x0004,
                PublishErrorResponseV1::class,
                // publisherId (uint8) + errors count (uint32) + empty array
                pack('C', 1) . pack('N', 0),
            ],
            'DELIVER' => [
                0x0008,
                DeliverResponseV1::class,
                // subscriptionId (uint8) + empty chunk data
                pack('C', 1),
            ],
            'METADATA_UPDATE' => [
                0x0010,
                MetadataUpdateResponseV1::class,
                // code (uint16) + stream (string with length prefix)
                pack('n', 1) . pack('n', 5) . 'test1',
            ],
            'METADATA_RESPONSE' => [
                0x800f,
                MetadataResponseV1::class,
                // correlationId + empty brokers array + empty streamMetadata array
                pack('N', 1) . pack('N', 0) . pack('N', 0),
            ],
            'QUERY_PUBLISHER_SEQUENCE_RESPONSE' => [
                0x8005,
                QueryPublisherSequenceResponseV1::class,
                // correlationId + responseCode OK + sequence (uint64)
                pack('N', 1) . pack('n', 0x0001) . pack('J', 0),
            ],
            'QUERY_OFFSET_RESPONSE' => [
                0x800b,
                QueryOffsetResponseV1::class,
                // correlationId + responseCode OK + offset (uint64)
                pack('N', 1) . pack('n', 0x0001) . pack('J', 0),
            ],
            'HEARTBEAT' => [
                0x0017,
                HeartbeatRequestV1::class,
                // Heartbeat has no correlationId in server-push frame
                '',
            ],
            'CONSUMER_UPDATE' => [
                0x001a,
                ConsumerUpdateQueryV1::class,
                // correlationId + subscriptionId (uint8) + active (uint8)
                pack('N', 1) . pack('C', 1) . pack('C', 1),
            ],
            'CREDIT_RESPONSE' => [
                0x8009,
                CreditResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'TUNE' => [
                0x0014,
                TuneRequestV1::class,
                // Tune has no correlationId: frameMax (uint32) + heartbeat (uint32)
                pack('N', 0) . pack('N', 0),
            ],
            'SASL_HANDSHAKE_RESPONSE' => [
                0x8012,
                SaslHandshakeResponseV1::class,
                // correlationId + responseCode OK + empty mechanisms array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'SASL_AUTHENTICATE_RESPONSE' => [
                0x8013,
                SaslAuthenticateResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'OPEN_RESPONSE' => [
                0x8015,
                OpenResponseV1::class,
                // correlationId + responseCode OK + empty connectionProperties array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'CLOSE_RESPONSE' => [
                0x8016,
                CloseResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'PEER_PROPERTIES_RESPONSE' => [
                0x8011,
                PeerPropertiesResponseV1::class,
                // correlationId + responseCode OK + empty properties array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'STREAM_STATS_RESPONSE' => [
                0x801c,
                StreamStatsResponseV1::class,
                // correlationId + responseCode OK + empty stats array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'PARTITIONS_RESPONSE' => [
                0x8019,
                PartitionsResponseV1::class,
                // correlationId + responseCode OK + empty partitions array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'ROUTE_RESPONSE' => [
                0x8018,
                RouteResponseV1::class,
                // correlationId + responseCode OK + empty streams array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'CREATE_SUPER_STREAM_RESPONSE' => [
                0x801d,
                CreateSuperStreamResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'DELETE_SUPER_STREAM_RESPONSE' => [
                0x801e,
                DeleteSuperStreamResponseV1::class,
                pack('N', 1) . pack('n', 0x0001),
            ],
            'EXCHANGE_COMMAND_VERSIONS_RESPONSE' => [
                0x801b,
                ExchangeCommandVersionsResponseV1::class,
                // correlationId + responseCode OK + empty versions array
                pack('N', 1) . pack('n', 0x0001) . pack('N', 0),
            ],
            'RESOLVE_OFFSET_SPEC_RESPONSE' => [
                0x801f,
                ResolveOffsetSpecResponseV1::class,
                // correlationId + responseCode OK + offsetType (uint16) + offset (uint64)
                pack('N', 1) . pack('n', 0x0001) . pack('n', 1) . pack('J', 0),
            ],
        ];
    }
}
