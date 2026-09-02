<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client\Routing;

use CrazyGoat\RabbitStream\Contract\ConnectionInterface;
use CrazyGoat\RabbitStream\Exception\NoRouteForKeyException;

/**
 * Routes a message using the broker's exchange-binding based routing
 * ({@see ConnectionInterface::route()}), one Route request per distinct
 * routing key — the result is cached in memory so repeated keys never
 * trigger a second round trip.
 */
final class KeyRoutingStrategy implements RoutingStrategy
{
    /** @var array<string, list<string>> routingKey => partitions */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $superStream,
    ) {
    }

    /** @return list<string> */
    public function route(string $routingKey, array $partitions): array
    {
        if (isset($this->cache[$routingKey])) {
            return $this->cache[$routingKey];
        }

        $streams = array_values($this->connection->route($routingKey, $this->superStream));
        if ($streams === []) {
            throw new NoRouteForKeyException($routingKey, $this->superStream);
        }

        $this->cache[$routingKey] = $streams;
        return $streams;
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
