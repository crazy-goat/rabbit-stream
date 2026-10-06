<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Contract;

use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Guards #419: every public method of every interface in src/Contract/ carries
 * a docblock with a prose description, the interfaces are the extension and
 * mocking seams added by #216, so they are what a test-double author reads
 * first. Every case of ResponseCodeEnum and KeyEnum is documented too.
 *
 * The accuracy of the prose and of the `@throws` sets cannot be asserted
 * reflexively — it depends on the transitive throw paths through the concrete
 * implementations, which a reflection test cannot see. What reflection can see
 * is checked here: every public interface method has a non-empty prose
 * description, every declared parameter has a matching `@param`, every method
 * with a non-void return type has a descriptive `@return` (the form Rector's
 * DEAD_CODE set keeps — FAQ-008), every documented `@throws` names a class or
 * interface that exists, and every documented `@throws` set matches the set
 * derived by tracing the real implementations (EXPECTED_THROWS).
 */
class ContractDocblockTest extends TestCase
{
    private const CONTRACT_DIR = __DIR__ . '/../../src/Contract';
    private const EXCEPTION_NAMESPACE = 'CrazyGoat\\RabbitStream\\Exception\\';

    /**
     * The exact `@throws` set each interface method must document, keyed by
     * "Interface::method". The sets were derived by tracing the concrete
     * implementations (`Connection`, `Consumer`, `Producer`,
     * `SuperStreamConsumer`, `SuperStreamProducer`) and their transitive throw
     * paths, not guessed. Pinning them makes removing a real tag — or adding a
     * wrong one — fail the build instead of silently weakening the contract.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_THROWS = [
        // ConnectionInterface
        'ConnectionInterface::createStream' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::deleteStream' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::createSuperStream' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::deleteSuperStream' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::route' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::partitions' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::streamExists' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::getStreamStats' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::getMetadata' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::queryOffset' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::close' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::createProducer' => [
            'ConnectionException',
            'InvalidArgumentException',
            'ProtocolException',
            'UnexpectedResponseException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::createConsumer' => [
            'ConnectionException',
            'InvalidArgumentException',
            'ProtocolException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::createSuperStreamProducer' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::createSuperStreamConsumer' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'InvalidArgumentException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConnectionInterface::readLoop' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'TimeoutException',
        ],
        'ConnectionInterface::supportsCommandVersion' => [],
        'ConnectionInterface::getSupportedCommandVersions' => [],
        'ConnectionInterface::storeOffset' => [
            'ConnectionException',
            'InvalidArgumentException',
            'TimeoutException',
        ],

        // ConsumerInterface
        'ConsumerInterface::read' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
            'InvalidArgumentException',
        ],
        'ConsumerInterface::readOne' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
            'InvalidArgumentException',
        ],
        'ConsumerInterface::hasUnread' => [],
        'ConsumerInterface::drain' => [
            'ConnectionException',
            'TimeoutException',
        ],
        'ConsumerInterface::storeOffset' => [
            'ProtocolException',
            'ConnectionException',
            'TimeoutException',
        ],
        'ConsumerInterface::queryOffset' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConsumerInterface::close' => [
            'ProtocolException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'ConsumerInterface::isActive' => [],
        'ConsumerInterface::onConsumerUpdate' => [],

        // ProducerInterface
        'ProducerInterface::send' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'ProducerInterface::sendBatch' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'ProducerInterface::sendWithFilter' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'ProducerInterface::close' => [
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
            'TimeoutException',
        ],
        'ProducerInterface::waitForConfirms' => [
            'TimeoutException',
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
        ],
        'ProducerInterface::getLastPublishingId' => [],
        'ProducerInterface::querySequence' => [
            'InvalidArgumentException',
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
            'TimeoutException',
            'UnexpectedResponseException',
        ],
        'ProducerInterface::getPendingConfirms' => [],

        // SuperStreamConsumerInterface
        'SuperStreamConsumerInterface::read' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
            'InvalidArgumentException',
        ],
        'SuperStreamConsumerInterface::readOne' => [
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
            'InvalidArgumentException',
        ],
        'SuperStreamConsumerInterface::storeOffset' => [
            'InvalidArgumentException',
            'ProtocolException',
            'ConnectionException',
            'TimeoutException',
        ],
        'SuperStreamConsumerInterface::queryOffset' => [
            'InvalidArgumentException',
            'ProtocolException',
            'UnexpectedResponseException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],
        'SuperStreamConsumerInterface::getPartitions' => [],
        'SuperStreamConsumerInterface::getConsumers' => [],
        'SuperStreamConsumerInterface::isActive' => [
            'InvalidArgumentException',
        ],
        'SuperStreamConsumerInterface::close' => [
            'ProtocolException',
            'ConnectionException',
            'DeserializationException',
            'TimeoutException',
        ],

        // SuperStreamProducerInterface
        'SuperStreamProducerInterface::send' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'SuperStreamProducerInterface::sendBatch' => [
            'ConnectionException',
            'DeserializationException',
            'InvalidArgumentException',
            'ProtocolException',
            'TimeoutException',
        ],
        'SuperStreamProducerInterface::waitForConfirms' => [
            'TimeoutException',
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
        ],
        'SuperStreamProducerInterface::getPendingConfirms' => [],
        'SuperStreamProducerInterface::getPartitions' => [],
        'SuperStreamProducerInterface::close' => [
            'ConnectionException',
            'DeserializationException',
            'ProtocolException',
            'TimeoutException',
        ],

        // CorrelationInterface
        'CorrelationInterface::getCorrelationId' => [],
        'CorrelationInterface::withCorrelationId' => [],

        // KeyVersionInterface
        'KeyVersionInterface::getVersion' => [],
        'KeyVersionInterface::getKey' => [],
    ];

    public function testEveryPublicInterfaceMethodIsDocumented(): void
    {
        $missingDocblock = [];
        $missingDescription = [];
        $missingParam = [];
        $missingReturn = [];

        foreach ($this->interfaceMethods() as $method) {
            $label = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
            $docblock = $method->getDocComment();

            if ($docblock === false || trim($docblock) === '') {
                $missingDocblock[] = $label;
                continue;
            }

            if ($this->description($docblock) === '') {
                $missingDescription[] = $label;
            }

            foreach ($method->getParameters() as $parameter) {
                $tag = '@param\b[^\n]*\$' . preg_quote($parameter->getName(), '/') . '\b';
                if (preg_match('/' . $tag . '/', $docblock) !== 1) {
                    $missingParam[] = $label . ' (missing @param $' . $parameter->getName() . ')';
                }
            }

            if ($this->needsReturnTag($method) && !$this->hasReturnDescription($docblock)) {
                $missingReturn[] = $label;
            }
        }

        $this->assertSame(
            [],
            $missingDocblock,
            "Public interface methods in src/Contract/ without a docblock:\n" . implode("\n", $missingDocblock)
        );
        $this->assertSame(
            [],
            $missingDescription,
            "Public interface methods in src/Contract/ whose docblock has no prose description:\n"
                . implode("\n", $missingDescription)
        );
        $this->assertSame(
            [],
            $missingParam,
            "Public interface methods in src/Contract/ missing an @param tag:\n" . implode("\n", $missingParam)
        );
        $this->assertSame(
            [],
            $missingReturn,
            "Public interface methods in src/Contract/ whose non-void return type has a missing "
                . "or description-less @return tag:\n"
                . implode("\n", $missingReturn)
        );
    }

    /**
     * A documented `@throws Foo` that names a non-existent class or interface
     * is worse than no tag at all: it sends a caller looking for an exception
     * the library cannot raise. Names are resolved as written first, then
     * against the library exception namespace, so both `@throws
     * ProtocolException` and `@throws \RuntimeException` are accepted.
     */
    public function testEveryDocumentedThrowsNamesARealClass(): void
    {
        $unknown = [];

        foreach ($this->interfaceMethods() as $method) {
            $docblock = $method->getDocComment();
            if ($docblock === false) {
                continue;
            }

            $label = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
            foreach ($this->unknownThrows($docblock, $label) as $name) {
                $unknown[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Documented @throws classes that do not exist:\n" . implode("\n", $unknown)
        );
    }

    /**
     * Pin the exact `@throws` set of every interface method. The "accuracy
     * cannot be asserted reflexively" caveat is covered by EXPECTED_THROWS: a
     * documented-but-wrong class is caught by
     * testEveryDocumentedThrowsNamesARealClass, and a *missing* tag fails here.
     */
    public function testEveryInterfaceMethodDeclaresItsExpectedThrows(): void
    {
        $actual = [];

        foreach ($this->interfaceMethods() as $method) {
            $docblock = $method->getDocComment();
            $throws = $docblock === false ? [] : $this->documentedThrows($docblock);
            sort($throws);
            $actual[$this->shortName($method) . '::' . $method->getName()] = $throws;
        }
        ksort($actual);

        $expected = self::EXPECTED_THROWS;
        foreach ($expected as &$throws) {
            sort($throws);
        }
        unset($throws);
        ksort($expected);

        $mismatches = [];
        foreach (array_unique(array_merge(array_keys($actual), array_keys($expected))) as $name) {
            if (($actual[$name] ?? null) !== ($expected[$name] ?? null)) {
                $mismatches[$name] = [
                    'expected' => $expected[$name] ?? null,
                    'actual' => $actual[$name] ?? null,
                ];
            }
        }

        $this->assertSame(
            $expected,
            $actual,
            "Interface methods whose documented @throws set does not match EXPECTED_THROWS "
                . "(update the map if the real throw paths changed):\n"
                . var_export($mismatches, true)
        );
    }

    /**
     * Every case of the two public enums carries a docblock with prose, so a
     * reader of ResponseCodeEnum or KeyEnum sees when each value is emitted /
     * used without leaving the source file.
     */
    public function testEveryEnumCaseHasADocblock(): void
    {
        $undocumented = [];

        foreach ([ResponseCodeEnum::class, KeyEnum::class] as $enum) {
            $classDocblock = (new \ReflectionClass($enum))->getDocComment();
            if ($classDocblock === false || trim($classDocblock) === '') {
                $undocumented[] = $enum . ' (class)';
            }

            foreach ((new \ReflectionEnum($enum))->getCases() as $case) {
                $label = $enum . '::' . $case->getName();
                $docblock = $case->getDocComment();
                if ($docblock === false || trim($docblock) === '') {
                    $undocumented[] = $label . ' (no docblock)';
                    continue;
                }
                if ($this->description($docblock) === '') {
                    $undocumented[] = $label . ' (no prose description)';
                }
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            "Enum classes and cases without a docblock:\n" . implode("\n", $undocumented)
        );
    }

    /**
     * Every public method declared by every interface file in src/Contract/.
     * Globbing the directory means a new interface is covered automatically.
     *
     * @return list<\ReflectionMethod>
     */
    private function interfaceMethods(): array
    {
        $methods = [];

        foreach (glob(self::CONTRACT_DIR . '/*Interface.php') ?: [] as $file) {
            $name = basename($file, '.php');
            /** @var class-string $interface */
            $interface = 'CrazyGoat\\RabbitStream\\Contract\\' . $name;
            if (!interface_exists($interface)) {
                continue;
            }

            foreach ((new \ReflectionClass($interface))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * The unqualified name of the class declaring $method, so the pinned
     * EXPECTED_THROWS map can be read without the full namespace.
     */
    private function shortName(\ReflectionMethod $method): string
    {
        $name = $method->getDeclaringClass()->getName();

        return substr($name, (int) strrpos($name, '\\') + 1);
    }

    /**
     * The `@throws` class names in a docblock that do not resolve to a real
     * class or interface.
     *
     * @return list<string>
     */
    private function unknownThrows(string $docblock, string $label): array
    {
        $unknown = [];

        preg_match_all('/@throws\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $docblock, $matches);
        foreach ($matches[1] as $name) {
            $name = ltrim($name, '\\');
            if (class_exists($name) || interface_exists($name)) {
                continue;
            }
            if (class_exists(self::EXCEPTION_NAMESPACE . $name)) {
                continue;
            }
            if (interface_exists(self::EXCEPTION_NAMESPACE . $name)) {
                continue;
            }

            $unknown[] = $label . ' -> ' . $name;
        }

        return $unknown;
    }

    /**
     * The `@throws` class names in a docblock, with a leading backslash
     * stripped.
     *
     * @return list<string>
     */
    private function documentedThrows(string $docblock): array
    {
        preg_match_all('/@throws\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $docblock, $matches);

        return array_map(
            static fn (string $name): string => ltrim($name, '\\'),
            $matches[1]
        );
    }

    /**
     * The prose description: the docblock text before the first `@tag`.
     */
    private function description(string $docblock): string
    {
        $body = preg_replace('~^/\*\*|\*/$~', '', trim($docblock)) ?? '';
        $lines = explode("\n", $body);
        $prose = [];

        foreach ($lines as $line) {
            $line = ltrim(trim($line), '*');
            $line = trim($line);
            if (str_starts_with($line, '@')) {
                break;
            }
            $prose[] = $line;
        }

        return trim(implode(' ', $prose));
    }

    /**
     * Whether the docblock has an `@return` tag carrying a description after
     * its type. A bare `@return <type>` whose type merely repeats the native
     * return type is removed by Rector's DEAD_CODE set (FAQ-008), so tag
     * presence alone is not enough — the gate must require prose too. A
     * description may sit on the tag line or on the continuation line(s).
     */
    private function hasReturnDescription(string $docblock): bool
    {
        $body = preg_replace('~^/\*\*|\*/$~', '', trim($docblock)) ?? '';
        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            $line = trim(ltrim(trim($line), '*'));
            if (preg_match('/^@return\s+(?:\S*<[^>]*>|\S*\{[^}]*\}|\S+)\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }
            if (trim($matches[1]) !== '') {
                return true;
            }
            for ($next = $index + 1, $count = count($lines); $next < $count; $next++) {
                $continuation = trim(ltrim(trim($lines[$next]), '*'));
                if ($continuation === '' || str_starts_with($continuation, '@')) {
                    break;
                }
                return true;
            }
        }

        return false;
    }

    private function needsReturnTag(\ReflectionMethod $method): bool
    {
        $name = strtolower($method->getName());
        if ($name === '__construct' || $name === '__destruct') {
            return false;
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType) {
            // No declared return type, or a union/intersection type: there is no
            // single `void` to exempt, so a tag is expected whenever a type was
            // declared at all.
            return $returnType !== null;
        }

        return $returnType->getName() !== 'void';
    }
}
