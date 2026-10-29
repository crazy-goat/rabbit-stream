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
final class TlsConfig implements \JsonSerializable
{
    /**
     * The passphrase lives in a Secret holder, not in a string property, so that
     * var_export(), serialize(), json_encode(), the (array) cast, print_r() and
     * the trace of any object that holds this config do not show it (#948).
     * Read it with getPassphrase().
     */
    private readonly ?Secret $passphrase;

    public function __construct(
        public readonly ?string $cafile = null,
        public readonly ?string $localCert = null,
        public readonly ?string $localPk = null,
        #[\SensitiveParameter] ?string $passphrase = null,
        public readonly ?string $peerName = null,
        public readonly bool $verifyPeer = true,
        public readonly bool $verifyPeerName = true,
    ) {
        $this->passphrase = $passphrase === null ? null : new Secret($passphrase);
    }

    /** The private-key passphrase, or null when none is set. */
    public function getPassphrase(): ?string
    {
        return $this->passphrase?->reveal();
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
            'passphrase' => $this->passphrase instanceof \CrazyGoat\RabbitStream\VO\Secret ? '***' : null,
            'peerName' => $this->peerName,
            'verifyPeer' => $this->verifyPeer,
            'verifyPeerName' => $this->verifyPeerName,
        ];
    }

    /**
     * Return the configuration fields without the private-key passphrase.
     *
     * @return array<string, bool|string|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'cafile' => $this->cafile,
            'localCert' => $this->localCert,
            'localPk' => $this->localPk,
            'peerName' => $this->peerName,
            'verifyPeer' => $this->verifyPeer,
            'verifyPeerName' => $this->verifyPeerName,
        ];
    }

    /**
     * Return the state for serialize() without the private-key passphrase.
     * An unserialized instance has a null passphrase.
     *
     * @return array<string, bool|string|null>
     */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /** @param array<string, bool|string|null> $data */
    public function __unserialize(array $data): void
    {
        $this->cafile = is_string($data['cafile'] ?? null) ? $data['cafile'] : null;
        $this->localCert = is_string($data['localCert'] ?? null) ? $data['localCert'] : null;
        $this->localPk = is_string($data['localPk'] ?? null) ? $data['localPk'] : null;
        $this->passphrase = null;
        $this->peerName = is_string($data['peerName'] ?? null) ? $data['peerName'] : null;
        $this->verifyPeer = ($data['verifyPeer'] ?? true) !== false;
        $this->verifyPeerName = ($data['verifyPeerName'] ?? true) !== false;
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
        $passphrase = $this->getPassphrase();
        if ($passphrase !== null) {
            $ssl['passphrase'] = $passphrase;
        }
        if ($this->peerName !== null) {
            $ssl['peer_name'] = $this->peerName;
        }

        return ['ssl' => $ssl];
    }
}
