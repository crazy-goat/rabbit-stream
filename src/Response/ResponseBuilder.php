<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;

class ResponseBuilder
{
    public static function fromResponseBuffer(ReadBuffer $responseBuffer): object
    {
        $command = CommandCode::fromStreamCode($responseBuffer->getUint16());
        $version = $responseBuffer->getUint16();

        $responseBuffer->rewind();
        return match ($command) {
            CommandCode::PEER_PROPERTIES => $version === 1 ? new PeerPropertiesResponseV1($responseBuffer) : throw new \Exception('Unexpected version'),
            CommandCode::SASL_HANDSHAKE => $version === 1 ? new SaslHandshakeResponseV1($responseBuffer) : throw new \Exception('Unexpected version'),
            CommandCode::SASL_AUTHENTICATE => $version === 1 ? new SaslAuthenticateResponseV1($responseBuffer) : throw new \Exception('Unexpected version'),
            CommandCode::TUNE => $version === 1 ? TuneRequestV1::fromStreamBuffer($responseBuffer): throw new \Exception('Unexpected version'),
            CommandCode::OPEN_RESPONSE => $version === 1 ? OpenResponseV1::fromStreamBuffer($responseBuffer): throw new \Exception('Unexpected version'),
            default => throw new \Exception('Unexpected match value'),
        };
    }
}