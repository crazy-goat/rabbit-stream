<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Serializer;

interface BinarySerializerInterface
{
    /**
     * Converts a Request object to the binary frame content, without the 4-byte size prefix.
     * The default implementation calls ToStreamBufferInterface::toStreamBuffer() and returns its bytes.
     */
    public function serialize(object $request): string;

    /**
     * Converts a binary frame to a Response object.
     * The method returns an object. It does not return an array.
     */
    public function deserialize(string $frame): object;
}
