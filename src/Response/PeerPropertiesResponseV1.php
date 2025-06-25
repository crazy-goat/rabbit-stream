<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\ResponseCode;
use CrazyGoat\StreamyCarrot\VO\KeyValue;

class PeerPropertiesResponseV1 implements ResponseInterface
{
    private int $correlationId = 0;
    private ResponseCode $responseCode;
    private array $peerProperty;

    public function __construct(ReadBuffer $responseBuffer)
    {
        if (CommandCode::fromStreamCode($responseBuffer->getUint16()) !== CommandCode::PEER_PROPERTIES) {
            throw new \Exception('Unexpected command code');
        }

        if ($responseBuffer->getUint16() !== 1) {
            throw new \Exception('Unexpected version');
        }

        $this->correlationId = $responseBuffer->getUint32();
        $this->responseCode = ResponseCode::from($responseBuffer->getUint16());
        $this->peerProperty = $responseBuffer->getObjectArray(KeyValue::class);
    }

    public function getCorrelationId(): int
    {
        return $this->correlationId;
    }

    public function getPeerProperty(): array
    {
        return $this->peerProperty;
    }
}