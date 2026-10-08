<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

final class CredentialsInTracesTest extends TestCase
{
    private const PASSWORD = 'pw-S3cr3t';
    private const PASSPHRASE = 'pk-S3cr3t';

    public function testConnectFailureTraceDoesNotContainPassword(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('Sensitive parameter trace redaction requires PHP 8.2+.');
        }

        $exception = null;
        try {
            Connection::create(
                host: '127.0.0.1',
                port: 1,
                user: 'app',
                password: self::PASSWORD,
                socketTimeout: 1.0,
            );
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'Expected a connection failure.');
        self::assertStringNotContainsString(self::PASSWORD, $exception->getTraceAsString());
        self::assertStringNotContainsString(self::PASSWORD, var_export($this->traceArgs($exception), true));
        $createFrame = $this->createFrame($exception);
        self::assertNotNull($createFrame, 'Connection::create() should appear in the exception trace.');
        self::assertArrayHasKey(3, $createFrame['args']);
        self::assertInstanceOf(\SensitiveParameterValue::class, $createFrame['args'][3]);
    }

    public function testValidationErrorTraceDoesNotContainPassword(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('Sensitive parameter trace redaction requires PHP 8.2+.');
        }

        $exception = null;
        try {
            Connection::create(password: self::PASSWORD, requestedFrameMax: -1);
        } catch (\InvalidArgumentException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'Expected an invalid-argument exception.');
        self::assertStringNotContainsString(self::PASSWORD, $exception->getTraceAsString());
        self::assertStringNotContainsString(self::PASSWORD, var_export($this->traceArgs($exception), true));
        $createFrame = $this->createFrame($exception);
        self::assertNotNull($createFrame, 'Connection::create() should appear in the exception trace.');
        self::assertArrayHasKey(3, $createFrame['args']);
        self::assertInstanceOf(\SensitiveParameterValue::class, $createFrame['args'][3]);
    }

    public function testCreatePasswordIsSensitiveParameter(): void
    {
        self::assertNotEmpty(
            $this->parameter([Connection::class, 'create'], 'password')->getAttributes(\SensitiveParameter::class)
        );
    }

    public function testSaslAuthenticatePasswordIsSensitiveParameter(): void
    {
        $attributes = $this->parameter(
            [SaslAuthenticateRequestV1::class, '__construct'],
            'password'
        )->getAttributes(\SensitiveParameter::class);

        self::assertNotEmpty($attributes);
    }

    public function testTlsPassphraseIsSensitiveParameter(): void
    {
        self::assertNotEmpty(
            $this->parameter([TlsConfig::class, '__construct'], 'passphrase')->getAttributes(\SensitiveParameter::class)
        );
    }

    public function testTlsConfigDebugOutputDoesNotContainPassphrase(): void
    {
        $tls = new TlsConfig(localCert: '/etc/client.pem', localPk: '/etc/client.key', passphrase: self::PASSPHRASE);

        self::assertStringNotContainsString(self::PASSPHRASE, print_r($tls, true));
        ob_start();
        var_dump($tls);
        $dumped = (string) ob_get_clean();

        self::assertStringNotContainsString(self::PASSPHRASE, $dumped);
        self::assertStringContainsString('***', $dumped);
        self::assertSame('***', $tls->__debugInfo()['passphrase']);
    }

    /** @return list<mixed> */
    private function traceArgs(Throwable $exception): array
    {
        $args = [];
        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $arg) {
                $args[] = $arg;
            }
        }

        return $args;
    }

    /** @return array{class: string, function: string, args: list<mixed>}|null */
    private function createFrame(Throwable $exception): ?array
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === Connection::class && $frame['function'] === 'create') {
                return [
                    'class' => Connection::class,
                    'function' => 'create',
                    'args' => $frame['args'] ?? [],
                ];
            }
        }

        return null;
    }

    /** @param array{class-string, string} $method */
    private function parameter(array $method, string $name): ReflectionParameter
    {
        foreach ((new ReflectionMethod($method[0], $method[1]))->getParameters() as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }

        self::fail("No parameter \$$name");
    }
}
