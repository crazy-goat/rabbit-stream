<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use PHPUnit\Framework\TestCase;

/**
 * A real TLS connection to the broker TLS stream listener with mutual TLS (GitHub #906).
 *
 * run-e2e.sh generates a CA, a server certificate and a client certificate whose key is
 * encrypted with a passphrase, and starts the broker with the TLS listener (see
 * docker-compose.tls.yml). The test is skipped when that environment is missing.
 */
class TlsConnectionE2ETest extends TestCase
{
    private string $host;
    private int $port;
    private string $certDir;
    private string $passphrase;

    protected function setUp(): void
    {
        $port = getenv('RABBITMQ_TLS_PORT');
        $certDir = getenv('RABBITMQ_TLS_CERT_DIR');
        $passphrase = getenv('RABBITMQ_TLS_PASSPHRASE');
        if ($port === false || $certDir === false || $passphrase === false) {
            self::markTestSkipped('The TLS E2E environment is not set: run ./run-e2e.sh.');
        }

        $this->host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $this->port = (int) $port;
        $this->certDir = $certDir;
        $this->passphrase = $passphrase;
    }

    private function tlsConfig(?string $passphrase = null, bool $clientCertificate = true): TlsConfig
    {
        return new TlsConfig(
            cafile: $this->certDir . '/ca.pem',
            localCert: $clientCertificate ? $this->certDir . '/client.pem' : null,
            localPk: $clientCertificate ? $this->certDir . '/client.key' : null,
            passphrase: $clientCertificate ? ($passphrase ?? $this->passphrase) : null,
        );
    }

    private function connect(TlsConfig $tls): Connection
    {
        return Connection::create(
            host: $this->host,
            port: $this->port,
            socketTimeout: 5.0,
            tls: $tls,
        );
    }

    public function testPublishAndConsumeOverMutualTls(): void
    {
        $connection = $this->connect($this->tlsConfig());
        $streamName = 'test-tls-stream-' . uniqid();

        try {
            $connection->createStream($streamName);

            $producer = $connection->createProducer($streamName);
            $producer->send('hello over tls');
            $producer->waitForConfirms(timeout: 5);
            $producer->close();

            $consumer = $connection->createConsumer($streamName, OffsetSpec::first());
            $received = [];
            $deadline = microtime(true) + 5;
            while ($received === [] && microtime(true) < $deadline) {
                foreach ($consumer->read(timeout: 0.5) as $message) {
                    $received[] = $message->getBody();
                }
            }
            $consumer->close();

            self::assertSame(['hello over tls'], $received);
        } finally {
            $connection->deleteStream($streamName);
            $connection->close();
        }
    }

    public function testConnectFailsWithoutClientCertificate(): void
    {
        // The listener demands a client certificate, so this proves the test above used one.
        $this->expectException(RabbitStreamExceptionInterface::class);

        $this->connect($this->tlsConfig(clientCertificate: false));
    }

    public function testConnectFailsWithWrongKeyPassphrase(): void
    {
        $this->expectException(RabbitStreamExceptionInterface::class);

        $this->connect($this->tlsConfig(passphrase: 'wrong-passphrase'));
    }
}
