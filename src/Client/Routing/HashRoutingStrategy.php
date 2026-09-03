<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client\Routing;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

/**
 * Routes a message to exactly one partition by hashing its routing key with
 * MurmurHash3 x86_32 (seed 104729) and taking the UNSIGNED result modulo the
 * partition count.
 *
 * This is entirely client-side (no broker round trip) and, crucially, uses
 * the exact same hash function, seed and modulo scheme as the Java and .NET
 * RabbitMQ Stream clients, so producers written in different languages agree
 * on where a given routing key lands.
 */
final class HashRoutingStrategy implements RoutingStrategy
{
    public const SEED = 104729;

    /** @return list<string> exactly one partition */
    public function route(string $routingKey, array $partitions): array
    {
        if ($partitions === []) {
            throw new InvalidArgumentException('Cannot hash-route with an empty partition list');
        }

        $hash = Murmur3::hash32($routingKey, self::SEED);
        $index = $hash % count($partitions);

        return [$partitions[$index]];
    }
}
