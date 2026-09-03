<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class AmqpMessageDecoder
{
    /**
     * Wrap a ChunkEntry into a Message. The AMQP sections (body, properties,
     * applicationProperties, messageAnnotations) are NOT decoded here — decoding
     * is deferred to the first Message accessor call that needs them (see
     * Message::fromRawEntry()), so a caller that only reads the offset/timestamp,
     * or only some messages of a batch, never pays the AMQP decode cost for the rest.
     */
    public static function decode(ChunkEntry $entry): Message
    {
        return Message::fromRawEntry($entry->getOffset(), $entry->getTimestamp(), $entry->getData());
    }

    /**
     * Wrap multiple ChunkEntries into Messages (lazily decoded, see decode()).
     * @param iterable<ChunkEntry> $entries
     * @return Message[]
     */
    public static function decodeAll(iterable $entries): array
    {
        $messages = [];
        foreach ($entries as $entry) {
            $messages[] = self::decode($entry);
        }
        return $messages;
    }
}
