<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\ResponseCode;

class SaslAuthenticateResponseV1 implements ResponseInterface
{

    private int $correlationId;
    private ResponseCode $responseCode;

    public function __construct(ReadBuffer $responseBuffer)
    {
        if (CommandCode::fromStreamCode($responseBuffer->getUint16()) !== CommandCode::SASL_AUTHENTICATE) {
            throw new \Exception('Unexpected command code');
        }

        if ($responseBuffer->getUint16() !== 1) {
            throw new \Exception('Unexpected version');
        }

        $this->correlationId = $responseBuffer->getUint32();
        $this->responseCode = ResponseCode::from($responseBuffer->getUint16());
    }
}