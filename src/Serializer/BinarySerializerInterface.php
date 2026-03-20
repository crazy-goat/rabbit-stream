<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Serializer;

interface BinarySerializerInterface
{
    /**
     * Converts a Request object to a binary frame (without the 4-byte length prefix).
     * Uses Request::toArray() to get the data, then serializes to binary.
     */
    public function serialize(object $request): string;

    /**
     * Converts a binary frame to a Response object.
     * Deserializes binary to array, then uses Response::fromArray() to build the object.
     */
    public function deserialize(string $frame): object;
}
