<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

/**
 * TLS transport options for a StreamConnection (GitHub #400).
 *
 * Peer and hostname verification are enabled by default: disabling them must
 * be a deliberate act by the caller, never an implicit fallback. Pass an
 * instance to Connection::create(tls: ...) or new StreamConnection(tls: ...) to
 * use the encrypted transport (RabbitMQ stream listener on port 5551).
 */
final class TlsConfig
{
    public function __construct(
        public readonly ?string $cafile = null,
        public readonly ?string $localCert = null,
        public readonly ?string $localPk = null,
        #[\SensitiveParameter] public readonly ?string $passphrase = null,
        public readonly ?string $peerName = null,
        public readonly bool $verifyPeer = true,
        public readonly bool $verifyPeerName = true,
    ) {
    }

    /**
     * Return the configuration fields with the private-key passphrase masked.
     *
     * @return array<string, bool|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'cafile' => $this->cafile,
            'localCert' => $this->localCert,
            'localPk' => $this->localPk,
            'passphrase' => $this->passphrase === null ? null : '***',
            'peerName' => $this->peerName,
            'verifyPeer' => $this->verifyPeer,
            'verifyPeerName' => $this->verifyPeerName,
        ];
    }

    /**
     * Options array for stream_context_create() on an ssl:// transport.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toStreamContext(): array
    {
        $ssl = [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeerName,
        ];

        if ($this->cafile !== null) {
            $ssl['cafile'] = $this->cafile;
        }
        if ($this->localCert !== null) {
            $ssl['local_cert'] = $this->localCert;
        }
        if ($this->localPk !== null) {
            $ssl['local_pk'] = $this->localPk;
        }
        if ($this->passphrase !== null) {
            $ssl['passphrase'] = $this->passphrase;
        }
        if ($this->peerName !== null) {
            $ssl['peer_name'] = $this->peerName;
        }

        return ['ssl' => $ssl];
    }
}
