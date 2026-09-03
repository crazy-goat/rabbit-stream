<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client\Routing;

use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;
use CrazyGoat\RabbitStream\Contract\ConnectionInterface;
use CrazyGoat\RabbitStream\Exception\NoRouteForKeyException;
use PHPUnit\Framework\TestCase;

class KeyRoutingStrategyTest extends TestCase
{
    public function testCacheHitMeansOnlyOneUnderlyingRouteCall(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('route')
            ->with('key1', 'my-super-stream')
            ->willReturn(['my-super-stream-0']);

        $strategy = new KeyRoutingStrategy($connection, 'my-super-stream');

        $first = $strategy->route('key1', ['my-super-stream-0', 'my-super-stream-1']);
        $second = $strategy->route('key1', ['my-super-stream-0', 'my-super-stream-1']);

        $this->assertSame(['my-super-stream-0'], $first);
        $this->assertSame($first, $second);
    }

    public function testNoRouteThrowsNoRouteForKeyException(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('route')
            ->with('unbound-key', 'my-super-stream')
            ->willReturn([]);

        $strategy = new KeyRoutingStrategy($connection, 'my-super-stream');

        $this->expectException(NoRouteForKeyException::class);
        $strategy->route('unbound-key', ['my-super-stream-0']);
    }
}
