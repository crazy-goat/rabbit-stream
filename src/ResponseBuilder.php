<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Response\OpenResponseV1;
use CrazyGoat\StreamyCarrot\Response\PeerPropertiesResponseV1;
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
            default => throw new \Exception('Unexpected match value'),
        };
    }

    private static function getV1(KeyEnum $command, ReadBuffer $responseBuffer): ?object
    {
        return match ($command) {
            KeyEnum::TUNE =>  TuneRequestV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_HANDSHAKE_RESPONSE => SaslHandshakeResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::SASL_AUTHENTICATE_RESPONSE => SaslAuthenticateResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::OPEN_RESPONSE =>  OpenResponseV1::fromStreamBuffer($responseBuffer),
            KeyEnum::PEER_PROPERTIES_RESPONSE => PeerPropertiesResponseV1::fromStreamBuffer($responseBuffer),
            default => throw new \Exception('Unexpected match value'),
        };
    }
}