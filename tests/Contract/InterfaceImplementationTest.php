<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Contract;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Client\SuperStreamConsumer;
use CrazyGoat\RabbitStream\Client\SuperStreamProducer;
use CrazyGoat\RabbitStream\Contract\ConnectionInterface;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamConsumerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamProducerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class InterfaceImplementationTest extends TestCase
{
    /**
     * @return array<class-string<object>, class-string<object>>
     */
    private function clientContracts(): array
    {
        return [
            Connection::class => ConnectionInterface::class,
            Producer::class => ProducerInterface::class,
            Consumer::class => ConsumerInterface::class,
            SuperStreamProducer::class => SuperStreamProducerInterface::class,
            SuperStreamConsumer::class => SuperStreamConsumerInterface::class,
        ];
    }

    public function testPublicClientMethodsAreDeclaredOnTheirInterfaces(): void
    {
        foreach ($this->clientContracts() as $clientClass => $contractClass) {
            /** @var class-string<object> $clientClass */
            /** @var class-string<object> $contractClass */
            $this->assertClientMethodsMatchContract($clientClass, $contractClass);
        }
    }

    public function testContractGuardDetectsAMissingPublicMethod(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessage('missingMethod() is public but missing from');

        $client = new class {
            public function missingMethod(): void
            {
            }
        };
        $contract = new class {
        };

        /** @var class-string<object> $clientClass */
        $clientClass = $client::class;
        /** @var class-string<object> $contractClass */
        $contractClass = $contract::class;

        $this->assertPublicMethodIsDeclaredOnContract(
            $clientClass,
            $contractClass,
            new ReflectionMethod($clientClass, 'missingMethod'),
        );
    }

    /** @param class-string<object> $clientClass @param class-string<object> $contractClass */
    private function assertClientMethodsMatchContract(string $clientClass, string $contractClass): void
    {
        /** @var class-string<object> $clientClass */
        /** @var class-string<object> $contractClass */
        $clientMethods = (new ReflectionClass($clientClass))->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($clientMethods as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $this->assertPublicMethodIsDeclaredOnContract(
                $clientClass,
                $contractClass,
                $method,
            );
        }
    }

    /**
     * @param class-string<object> $client
     * @param class-string<object> $contract
     */
    private function assertPublicMethodIsDeclaredOnContract(
        string $client,
        string $contract,
        ReflectionMethod $method,
    ): void {
        $clientReflection = new ReflectionClass($client);
        $contractReflection = new ReflectionClass($contract);
        $docComment = $method->getDocComment();
        if ($docComment !== false && preg_match('/@internal\b/', $docComment) === 1) {
            return;
        }

        self::assertTrue(
            $contractReflection->hasMethod($method->getName()),
            sprintf(
                '%s::%s() is public but missing from %s',
                $clientReflection->getName(),
                $method->getName(),
                $contractReflection->getName(),
            ),
        );

        $contractMethod = $contractReflection->getMethod($method->getName());
        self::assertSame(
            $this->methodSignature($method),
            $this->methodSignature($contractMethod),
            sprintf(
                '%s::%s() signature differs from %s',
                $clientReflection->getName(),
                $method->getName(),
                $contractReflection->getName(),
            ),
        );
    }

    /**
     * @return array{
     *     parameters: list<array{
     *         name: string,
     *         type: string|null,
     *         byReference: bool,
     *         variadic: bool,
     *         optional: bool,
     *         default: mixed
     *     }>,
     *     returnType: string|null,
     *     returnsReference: bool
     * }
     */
    private function methodSignature(ReflectionMethod $method): array
    {
        return [
            'parameters' => array_map(
                static fn (ReflectionParameter $parameter): array => [
                    'name' => $parameter->getName(),
                    'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
                    'byReference' => $parameter->isPassedByReference(),
                    'variadic' => $parameter->isVariadic(),
                    'optional' => $parameter->isOptional(),
                    'default' => $parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : null,
                ],
                $method->getParameters(),
            ),
            'returnType' => $method->hasReturnType() ? (string) $method->getReturnType() : null,
            'returnsReference' => $method->returnsReference(),
        ];
    }
}
