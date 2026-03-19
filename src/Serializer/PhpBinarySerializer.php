<?php

namespace CrazyGoat\RabbitStream\Serializer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\ResponseBuilder;

class PhpBinarySerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        if (!$request instanceof ToStreamBufferInterface) {
            throw new \InvalidArgumentException(
                'Request must implement ToStreamBufferInterface'
            );
        }

        return $request->toStreamBuffer()->getContents();
    }

    public function deserialize(string $frame): object
    {
        return ResponseBuilder::fromResponseBuffer(new ReadBuffer($frame));
    }
}
