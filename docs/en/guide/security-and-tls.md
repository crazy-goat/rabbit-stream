# Security and TLS

This guide covers the security settings of the client. It describes the SASL mechanism, the broker permissions that a stream user needs, and the TLS transport.

## Authentication

The client uses the SASL `PLAIN` mechanism only. `Connection::create()` checks that the broker offers `PLAIN`. If the broker does not offer it, the method throws `AuthenticationException`.

`PLAIN` sends the password as plain text. Use TLS when the network is not trusted. (Not tested.)

For the other exceptions of a failed connection, see [Error Handling](error-handling.md).

## Broker Permissions

The broker checks every permission. The client does not check them. A stream user needs these permissions. (Not tested.)

- `configure` on a stream, for `createStream()` and `deleteStream()`.
- `write` on a stream, for `createProducer()` and `send()`.
- `read` on a stream, for `createConsumer()`.

The user also needs access to the virtual host that `Connection::create()` names with `vhost`.

Run these commands on the broker to create a user with these permissions for the streams that start with `orders`. (Not tested.)

```bash
rabbitmqctl add_user app 'use-a-long-password'
rabbitmqctl set_permissions -p / app '^orders' '^orders' '^orders'
```

The three patterns set `configure`, `write` and `read` in this order. Replace `^orders` with a pattern for your streams.

## TLS Transport

Pass a `TlsConfig` to `Connection::create()` to use the encrypted transport. The TLS stream listener of the broker uses port 5551 by default. The plain transport uses port 5552.

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\TlsConfig;

$connection = Connection::create(
    host: 'rabbitmq.internal',
    port: 5551,
    user: 'app',
    password: 'use-a-long-password',
    tls: new TlsConfig(
        cafile: '/etc/ssl/ca.pem',
        localCert: '/etc/ssl/client.crt',
        localPk: '/etc/ssl/client.key',
        passphrase: 'key-passphrase',
    ),
);
```

The client completes the TLS handshake, with the client certificate and the key passphrase, within `socketTimeout`. After the handshake, the client sends the SASL messages over the encrypted connection. The E2E test `tests/E2E/TlsConnectionE2ETest.php` checks this flow against a broker with mutual TLS.

The client certificate does not replace the PLAIN login. The broker checks the certificate in the TLS handshake, then checks the PLAIN login. The E2E test `testPublishAndConsumeOverMutualTls` in `tests/E2E/TlsConnectionE2ETest.php` covers both checks.

With TLS 1.3, `connect()` can succeed when the broker rejects the client certificate. The rejection shows on the first read. The client then throws `ConnectionException`. Its message starts with `TLS peer rejected the connection:` and names the TLS alert. [TLS peer rejection](error-handling.md#tls-peer-rejection) shows an example.

### TlsConfig Options

| Option | Default | Meaning |
|---|---|---|
| `cafile` | `null` | CA bundle that checks the broker certificate. |
| `localCert` | `null` | Client certificate for mutual TLS. |
| `localPk` | `null` | Private key of the client certificate. |
| `passphrase` | `null` | Passphrase of the private key. Read it with `getPassphrase()`. |
| `peerName` | `null` | Host name for the SNI extension and for the certificate name check. Set it when the host is an IP address. |
| `verifyPeer` | `true` | Check the broker certificate. |
| `verifyPeerName` | `true` | Check that the certificate matches the host name. |

The passphrase is a private property. `getPassphrase()` returns it. The passphrase does not appear in `var_export()`, `serialize()`, `json_encode()`, the `(array)` cast, debug output or stack traces. Debug output shows `***` instead.

Verification is on by default. Set `verifyPeer` and `verifyPeerName` to `false` only for a local test broker with a self-signed certificate. Do not do this in production.

When the host is an IP address and `peerName` is not set, the client sends no SNI host name. Set `peerName` to send a host name. (Tested: `tests/Client/TlsSniTest.php`.)

The API reference for [Connection](../api-reference/connection.md) lists the `tls` parameter.

### Broker Side: Mutual TLS

The broker needs a TLS stream listener. This `rabbitmq.conf` block starts a listener on port 5551 and requires a client certificate. It is the file that the E2E tests use: [`docker/rabbitmq-tls.conf`](../../../docker/rabbitmq-tls.conf).

```ini
stream.listeners.ssl.default = 5551
ssl_options.cacertfile = /certs/ca.pem
ssl_options.certfile = /certs/server.pem
ssl_options.keyfile = /certs/server.key
ssl_options.verify = verify_peer
ssl_options.fail_if_no_peer_cert = true
```

Replace the paths with the files of your broker. `fail_if_no_peer_cert = true` makes the broker refuse a client that sends no certificate.
