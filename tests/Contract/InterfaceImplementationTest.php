<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Contract;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Contract\ConnectionInterface;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use PHPUnit\Framework\TestCase;

class InterfaceImplementationTest extends TestCase
{
    public function testConnectionImplementsConnectionInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(Connection::class, ConnectionInterface::class),
            'Connection class must implement ConnectionInterface'
        );
    }

    public function testProducerImplementsProducerInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(Producer::class, ProducerInterface::class),
            'Producer class must implement ProducerInterface'
        );
    }

    public function testConsumerImplementsConsumerInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(Consumer::class, ConsumerInterface::class),
            'Consumer class must implement ConsumerInterface'
        );
    }
}
