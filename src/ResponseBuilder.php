<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Request\HeartbeatRequestV1;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Response\ConsumerUpdateQueryV1;
use CrazyGoat\StreamyCarrot\Response\DeclarePublisherResponseV1;
use CrazyGoat\StreamyCarrot\Response\DeliverResponseV1;
use CrazyGoat\StreamyCarrot\Response\MetadataUpdateResponseV1;
use CrazyGoat\StreamyCarrot\Response\OpenResponseV1;
use CrazyGoat\StreamyCarrot\Response\PeerPropertiesResponseV1;
use CrazyGoat\StreamyCarrot\Response\PublishConfirmResponseV1;
use CrazyGoat\StreamyCarrot\Response\PublishErrorResponseV1;
use CrazyGoat\StreamyCarrot\Response\SaslAuthenticateResponseV1;
use CrazyGoat\StreamyCarrot\Response\SaslHandshakeResponseV1;

class ResponseBuilder
{
    public static function fromResponseBuffer(ReadBuffer $responseBuffer): object
    {
        $command = KeyEnum::fromStreamCode($responseBuffer->getUint16());
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
            KeyEnum::PUBLISH_CONFIRM => PublishConfirmResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PUBLISH_ERROR => PublishErrorResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::DELIVER => DeliverResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::METADATA_UPDATE => MetadataUpdateResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::HEARTBEAT => HeartbeatRequestV1::fromStreamBuffer($responseBuffer),
            KeyEnum::CONSUMER_UPDATE => ConsumerUpdateQueryV1::fromStreamBuffer($responseBuffer),
            KeyEnum::TUNE => TuneRequestV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_HANDSHAKE_RESPONSE => SaslHandshakeResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_AUTHENTICATE_RESPONSE => SaslAuthenticateResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::OPEN_RESPONSE => OpenResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PEER_PROPERTIES_RESPONSE => PeerPropertiesResponseV1::fromStreamBuffer($responseBuffer),
            default => throw new \Exception('Unexpected match value'),
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
