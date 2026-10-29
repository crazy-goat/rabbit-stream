<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use PHPUnit\Framework\TestCase;

class TlsConfigTest extends TestCase
{
    public function testDefaultsEnablePeerAndHostnameVerification(): void
    {
        $config = new TlsConfig();
        $context = $config->toStreamContext();

        self::assertTrue($context['ssl']['verify_peer']);
        self::assertTrue($context['ssl']['verify_peer_name']);
    }

    public function testEmptyConfigProducesMinimalContext(): void
    {
        $config = new TlsConfig();

        self::assertSame(
            ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]],
            $config->toStreamContext()
        );
    }

    public function testFullConfigMapsAllOptions(): void
    {
        $config = new TlsConfig(
            cafile: '/etc/ssl/ca.pem',
            localCert: '/etc/ssl/client.crt',
            localPk: '/etc/ssl/client.key',
            passphrase: 'secret',
            peerName: 'rabbit.internal',
            verifyPeer: false,
            verifyPeerName: false,
        );

        self::assertSame([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'cafile' => '/etc/ssl/ca.pem',
                'local_cert' => '/etc/ssl/client.crt',
                'local_pk' => '/etc/ssl/client.key',
                'passphrase' => 'secret',
                'peer_name' => 'rabbit.internal',
            ],
        ], $config->toStreamContext());
    }

    public function testVerificationCanBeDisabledExplicitly(): void
    {
        $context = (new TlsConfig(verifyPeer: false, verifyPeerName: false))->toStreamContext();

        self::assertFalse($context['ssl']['verify_peer']);
        self::assertFalse($context['ssl']['verify_peer_name']);
    }

    public function testJsonEncodeOmitsPassphrase(): void
    {
        $json = (string) json_encode(new TlsConfig(localPk: '/k.pem', passphrase: 'example-passphrase'));

        self::assertStringNotContainsString('example-passphrase', $json);
        self::assertStringContainsString('/k.pem', $json);
    }

    public function testSerializeOmitsPassphraseAndKeepsOtherFields(): void
    {
        $config = new TlsConfig(cafile: '/ca.pem', passphrase: 'example-passphrase', verifyPeer: false);
        $serialized = serialize($config);

        self::assertStringNotContainsString('example-passphrase', $serialized);

        $restored = unserialize($serialized);
        self::assertInstanceOf(TlsConfig::class, $restored);
        self::assertSame('/ca.pem', $restored->cafile);
        self::assertFalse($restored->verifyPeer);
        self::assertTrue($restored->verifyPeerName);
        self::assertNull($restored->passphrase);
    }

    public function testConnectionStillReadsPassphrase(): void
    {
        $context = (new TlsConfig(passphrase: 'example-passphrase'))->toStreamContext();

        self::assertSame('example-passphrase', $context['ssl']['passphrase']);
    }

    public function testStackTraceOfConnectionCreateHoldsNoPassphrase(): void
    {
        $trace = $this->traceOf(static function (): void {
            Connection::create(
                requestedFrameMax: -1,
                tls: new TlsConfig(passphrase: 'example-passphrase'),
            );
        });

        self::assertStringNotContainsString('example-passphrase', $trace);
    }

    public function testStackTraceOfStreamConnectionHoldsNoPassphrase(): void
    {
        $trace = $this->traceOf(static function (): void {
            new StreamConnection(socketTimeout: 0.0, tls: new TlsConfig(passphrase: 'example-passphrase'));
        });

        self::assertStringNotContainsString('example-passphrase', $trace);
    }

    private function traceOf(callable $call): string
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[\\SensitiveParameter] needs PHP 8.2');
        }
        ini_set('zend.exception_ignore_args', '0');

        try {
            $call();
        } catch (\Throwable $e) {
            return var_export($e->getTrace(), true) . json_encode($e->getTrace());
        }
        self::fail('Expected an exception');
    }
}
