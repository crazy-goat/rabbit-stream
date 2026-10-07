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
        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(
            // @phpstan-ignore function.alreadyNarrowedType
            is_subclass_of(Connection::class, ConnectionInterface::class),
            'Connection class must implement ConnectionInterface'
        );
    }

    public function testConnectionInterfaceMatchesConsumerFactoryParameters(): void
    {
        foreach (['createConsumer', 'createSuperStreamConsumer'] as $method) {
            $implementationParameters = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new \ReflectionMethod(Connection::class, $method))->getParameters(),
            );
            $interfaceParameters = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new \ReflectionMethod(ConnectionInterface::class, $method))->getParameters(),
            );

            self::assertSame(
                $implementationParameters,
                $interfaceParameters,
                $method . ' parameters differ from interface',
            );
        }
    }

    public function testConnectionInterfaceDeclaresIsConnected(): void
    {
        self::assertTrue((new \ReflectionClass(ConnectionInterface::class))->hasMethod('isConnected'));
        self::assertSame(
            'bool',
            (string) (new \ReflectionMethod(ConnectionInterface::class, 'isConnected'))->getReturnType(),
        );
    }

    public function testProducerImplementsProducerInterface(): void
    {
        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(
            // @phpstan-ignore function.alreadyNarrowedType
            is_subclass_of(Producer::class, ProducerInterface::class),
            'Producer class must implement ProducerInterface'
        );
    }

    public function testConsumerImplementsConsumerInterface(): void
    {
        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertTrue(
            // @phpstan-ignore function.alreadyNarrowedType
            is_subclass_of(Consumer::class, ConsumerInterface::class),
            'Consumer class must implement ConsumerInterface'
        );
    }
}
