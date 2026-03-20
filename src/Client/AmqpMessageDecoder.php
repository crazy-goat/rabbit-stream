<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class AmqpMessageDecoder
{
    /**
     * Decode a ChunkEntry into a Message.
     */
    public static function decode(ChunkEntry $entry): Message
    {
        $sections = AmqpDecoder::decodeMessage($entry->getData());

        $rawBody = $sections['body'] ?? null;
        if (is_array($rawBody)) {
            $body = array_values($rawBody);
        } elseif ($rawBody === null || is_scalar($rawBody)) {
            $body = $rawBody;
        } else {
            $body = null;
        }

        $properties = $sections['properties'] ?? [];
        $applicationProperties = $sections['applicationProperties'] ?? [];
        $messageAnnotations = $sections['messageAnnotations'] ?? [];

        return new Message(
            offset: $entry->getOffset(),
            timestamp: $entry->getTimestamp(),
            body: $body,
            properties: is_array($properties) ? $properties : [],
            applicationProperties: is_array($applicationProperties) ? $applicationProperties : [],
            messageAnnotations: is_array($messageAnnotations) ? $messageAnnotations : [],
        );
    }

    /**
     * Decode multiple ChunkEntries into Messages.
     * @param ChunkEntry[] $entries
     * @return Message[]
     */
    public static function decodeAll(array $entries): array
    {
        $messages = [];
        foreach ($entries as $entry) {
            $messages[] = self::decode($entry);
        }
        return $messages;
    }
}
