<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * Thrown by {@see \CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy}
 * when the broker's Route response for a routing key contains no partitions
 * (no exchange binding matches the key).
 */
class NoRouteForKeyException extends RabbitStreamException
{
    public function __construct(private readonly string $routingKey, private readonly string $superStream)
    {
        parent::__construct(
            sprintf(
                'No route found for routing key "%s" on super stream "%s"',
                $routingKey,
                $superStream
            )
        );
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getSuperStream(): string
    {
        return $this->superStream;
    }
}
