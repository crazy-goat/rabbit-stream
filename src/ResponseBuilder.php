<?php

namespace CrazyGoat\RabbitStream;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateQueryV1;
use CrazyGoat\RabbitStream\Response\CreditResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeletePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;

class ResponseBuilder
{
    public static function fromResponseBuffer(ReadBuffer $responseBuffer): object
    {
        $commandCode = $responseBuffer->getUint16();
        $command = KeyEnum::fromStreamCode($commandCode);
        $version = $responseBuffer->getUint16();

        $responseBuffer->rewind();
        return match ($version) {
            1 => self::getV1($command, $responseBuffer),
            2 => self::getV2($command, $responseBuffer),
            default => throw new \Exception('Unexpected match value'),
        };
    }

    private static function getV1(KeyEnum $command, ReadBuffer $responseBuffer): object
    {
        return match ($command) {
            KeyEnum::DECLARE_PUBLISHER_RESPONSE => DeclarePublisherResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::DELETE_PUBLISHER_RESPONSE => DeletePublisherResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SUBSCRIBE_RESPONSE => SubscribeResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::UNSUBSCRIBE_RESPONSE => UnsubscribeResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::CREATE_RESPONSE => CreateResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::DELETE_RESPONSE => DeleteStreamResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PUBLISH_CONFIRM => PublishConfirmResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PUBLISH_ERROR => PublishErrorResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::DELIVER => DeliverResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::METADATA_UPDATE => MetadataUpdateResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::METADATA_RESPONSE => MetadataResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::QUERY_PUBLISHER_SEQUENCE_RESPONSE => QueryPublisherSequenceResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::QUERY_OFFSET_RESPONSE => QueryOffsetResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::HEARTBEAT => HeartbeatRequestV1::fromStreamBuffer($responseBuffer),
            KeyEnum::CONSUMER_UPDATE => ConsumerUpdateQueryV1::fromStreamBuffer($responseBuffer),
            KeyEnum::CREDIT_RESPONSE => CreditResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::TUNE => TuneRequestV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_HANDSHAKE_RESPONSE => SaslHandshakeResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_AUTHENTICATE_RESPONSE => SaslAuthenticateResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::OPEN_RESPONSE => OpenResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::CLOSE_RESPONSE => CloseResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PEER_PROPERTIES_RESPONSE => PeerPropertiesResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::STREAM_STATS_RESPONSE => StreamStatsResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PARTITIONS_RESPONSE => PartitionsResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::ROUTE_RESPONSE => RouteResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::CREATE_SUPER_STREAM_RESPONSE => CreateSuperStreamResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::DELETE_SUPER_STREAM_RESPONSE => DeleteSuperStreamResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::EXCHANGE_COMMAND_VERSIONS_RESPONSE => ExchangeCommandVersionsResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE => ResolveOffsetSpecResponseV1::fromStreamBuffer($responseBuffer),
            default => throw new \Exception('Unexpected match value: ' . $command->name . ' (0x' . dechex($command->value) . ')'),
        };
    }

    private static function getV2(KeyEnum $command, ReadBuffer $responseBuffer): object
    {
        return match ($command) {
            KeyEnum::DELIVER => DeliverResponseV1::fromStreamBuffer($responseBuffer),
            default => throw new \Exception('Unexpected match value'),
        };
    }
}
