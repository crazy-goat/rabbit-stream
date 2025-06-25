<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\CorrelationInterface;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Request\ResponseCodeInterface;
use CrazyGoat\StreamyCarrot\ResponseCode;
use CrazyGoat\StreamyCarrot\VO\KeyValue;

class OpenResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    private int $correlationId;
    private ResponseCode $responseCode;
    /**
     * @var KeyValue[]
     */
    private array $connectionProperties;

    public function __construct(KeyValue ...$connectionProperties)
    {
        $this->connectionProperties = $connectionProperties;
    }

    public function getCorrelationId(): int
    {
        return $this->correlationId;
    }

    public function withCorrelationId(int $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        if (CommandCode::fromStreamCode($buffer->getUint16()) !== CommandCode::OPEN_RESPONSE) {
            throw new \Exception('Unexpected command code');
        }

        if ($buffer->getUint16() !== 1) {
            throw new \Exception('Unexpected version');
        }

        $correlationId = $buffer->getUint32();

        if (ResponseCode::from($buffer->getUint16()) !== ResponseCode::OK) {
            throw new \Exception('Unexpected response code');
        };

        $object = new self(...$buffer->getObjectArray(KeyValue::class));
        $object->withCorrelationId($correlationId);

        return $object;
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getKey(): int
    {
        return CommandCode::OPEN_RESPONSE->value;
    }
}