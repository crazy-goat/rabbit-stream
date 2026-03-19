<?php

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Stub Consumer class - full implementation in iteration 8
 */
class Consumer
{
    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $subscriptionId,
        private readonly OffsetSpec $offset,
        private readonly ?string $name = null,
        private readonly int $autoCommit = 0,
        private readonly int $initialCredit = 10,
    ) {
        // Stub implementation - full logic in iteration 8
    }
}
