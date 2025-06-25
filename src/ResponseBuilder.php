<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Response\OpenResponseV1;
use CrazyGoat\StreamyCarrot\Response\PeerPropertiesResponseV1;
use CrazyGoat\StreamyCarrot\Response\SaslAuthenticateResponseV1;
use CrazyGoat\StreamyCarrot\Response\SaslHandshakeResponseV1;

class ResponseBuilder
{
    public static function fromResponseBuffer(ReadBuffer $responseBuffer): object
    {
        $command = CommandCode::fromStreamCode($responseBuffer->getUint16());
        $version = $responseBuffer->getUint16();

        $responseBuffer->rewind();
        return match ($version) {
            1 => self::getV1($command, $responseBuffer),
            default => throw new \Exception('Unexpected match value'),
        };
    }

    private static function getV1(CommandCode $command, ReadBuffer $responseBuffer): ?object
    {
        return match ($command) {
            CommandCode::TUNE =>  TuneRequestV1::fromStreamBuffer($responseBuffer),
            CommandCode::SASL_HANDSHAKE_RESPONSE => SaslHandshakeResponseV1::fromStreamBuffer($responseBuffer),
            CommandCode::SASL_AUTHENTICATE_RESPONSE => SaslAuthenticateResponseV1::fromStreamBuffer($responseBuffer),
            CommandCode::OPEN_RESPONSE =>  OpenResponseV1::fromStreamBuffer($responseBuffer),
            CommandCode::PEER_PROPERTIES_RESPONSE => PeerPropertiesResponseV1::fromStreamBuffer($responseBuffer),
            default => throw new \Exception('Unexpected match value'),
        };
    }
}