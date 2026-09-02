<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client\Routing;

/**
 * Decides which partition(s) of a super stream a routing key maps to.
 *
 * @see HashRoutingStrategy for client-side (no broker round trip) hash routing
 * @see KeyRoutingStrategy for broker-resolved (exchange binding) key routing
 */
interface RoutingStrategy
{
    /**
     * @param string $routingKey the key a message is published with
     * @param list<string> $partitions the super stream's partition names
     * @return list<string> the partition(s) the message should be published to;
     *                       like the Java client, key routing can legitimately
     *                       return more than one partition for a single key
     */
    public function route(string $routingKey, array $partitions): array;
}
