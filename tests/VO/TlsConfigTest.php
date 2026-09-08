<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

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
}
