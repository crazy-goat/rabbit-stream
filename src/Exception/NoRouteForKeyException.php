<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * Thrown by {@see \CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy}
 * when the broker's Route response for a routing key contains no partitions
 * (no exchange binding matches the key).
 *
 * The key and super stream that failed are carried on the exception:
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\NoRouteForKeyException;
 *
 * try {
 *     $producer->send($message, $routingKey);
 * } catch (NoRouteForKeyException $e) {
 *     error_log(sprintf(
 *         'No partition for key "%s" on super stream "%s"',
 *         $e->getRoutingKey(),
 *         $e->getSuperStream()
 *     ));
 * }
 * ```
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
