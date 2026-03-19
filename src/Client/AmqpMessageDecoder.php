<?php

namespace CrazyGoat\RabbitStream\Client;

class AmqpMessageDecoder
{
    /**
     * Decode a ChunkEntry into a Message.
     */
    static public function decode(ChunkEntry $entry): Message
    {
        $sections = AmqpDecoder::decodeMessage($entry->getData());
        return new Message(
            offset: $entry->getOffset(),
            timestamp: $entry->getTimestamp(),
            body: $sections['body'],
            properties: $sections['properties'] ?? [],
            applicationProperties: $sections['applicationProperties'] ?? [],
            messageAnnotations: $sections['messageAnnotations'] ?? [],
        );
    }

    /**
     * Decode multiple ChunkEntries into Messages.
     * @param ChunkEntry[] $entries
     * @return Message[]
     */
    static public function decodeAll(array $entries): array
    {
        $messages = [];
        foreach ($entries as $entry) {
            $messages[] = self::decode($entry);
        }
        return $messages;
    }
}
