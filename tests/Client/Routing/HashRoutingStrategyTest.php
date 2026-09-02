<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client\Routing;

use CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy;
use PHPUnit\Framework\TestCase;

class HashRoutingStrategyTest extends TestCase
{
    public function testRouteIsDeterministic(): void
    {
        $strategy = new HashRoutingStrategy();
        $partitions = ['p0', 'p1', 'p2'];

        $first = $strategy->route('some-key', $partitions);
        $second = $strategy->route('some-key', $partitions);

        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
        $this->assertContains($first[0], $partitions);
    }

    public function testDistributionHitsAllPartitions(): void
    {
        $strategy = new HashRoutingStrategy();
        $partitions = ['p0', 'p1', 'p2'];

        $hit = [];
        for ($i = 0; $i < 100; $i++) {
            $routed = $strategy->route("key-{$i}", $partitions);
            $hit[$routed[0]] = true;
        }

        $this->assertCount(3, $hit, 'Expected key-0..key-99 to hit all 3 partitions at least once');
    }
}
