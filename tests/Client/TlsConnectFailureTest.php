<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\TestCase;

/**
 * A failed TLS handshake must name its reason in the ConnectionException message (GitHub #928).
 * PHP reports that reason only as warnings, so the test runs a real TLS listener in a child
 * process. The certificate is for broker.test and the client connects to 127.0.0.1, so the
 * peer name check fails.
 */
final class TlsConnectFailureTest extends TestCase
{
    private string $dir = '';

    /** @var resource|null */
    private $server;

    protected function setUp(): void
    {
        if (!extension_loaded('openssl') || !function_exists('proc_open')) {
            self::markTestSkipped('The openssl extension and proc_open are required');
        }

        $this->dir = sys_get_temp_dir() . '/rs-tls-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testFailedPeerNameCheckNamesTheReasonInTheMessage(): void
    {
        $port = $this->startTlsServer();

        $connection = new StreamConnection(
            '127.0.0.1',
            $port,
            socketTimeout: 3.0,
            tls: new TlsConfig(cafile: $this->dir . '/cert.pem')
        );

        try {
            $connection->connect();
            self::fail('Expected ConnectionException: the peer name does not match');
        } catch (ConnectionException $e) {
            self::assertStringStartsWith("Cannot connect to ssl://127.0.0.1:{$port}: ", $e->getMessage());
            self::assertStringContainsString('did not match expected CN', $e->getMessage());
            self::assertStringNotContainsString('(0)', $e->getMessage());
        }
    }

    public function testFailedTlsConnectClearsTheOpenSslErrorQueue(): void
    {
        $port = $this->startTlsServer();

        $connection = new StreamConnection(
            '127.0.0.1',
            $port,
            socketTimeout: 3.0,
            tls: new TlsConfig(cafile: $this->dir . '/cert.pem')
        );

        try {
            $connection->connect();
            self::fail('Expected ConnectionException: the peer name does not match');
        } catch (ConnectionException) {
            // Expected: the reason is checked in the test above.
        }

        self::assertFalse(openssl_error_string(), 'A failed TLS connect left OpenSSL errors in the queue');
    }

    /**
     * Create a certificate for broker.test, then start a TLS listener in a child process.
     * Return the port. The listener closes after one accept or after five seconds.
     */
    private function startTlsServer(): int
    {
        $keyFile = $this->dir . '/key.pem';
        $certFile = $this->dir . '/cert.pem';

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'Cannot create a private key');
        $csr = openssl_csr_new(['commonName' => 'broker.test'], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr, 'Cannot create a certificate request');
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert, 'Cannot sign the certificate');
        self::assertTrue(openssl_x509_export_to_file($cert, $certFile));
        self::assertTrue(openssl_pkey_export_to_file($key, $keyFile));

        $script = $this->dir . '/server.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $port, $certFile, $keyFile] = $argv;
            $context = stream_context_create(['ssl' => ['local_cert' => $certFile, 'local_pk' => $keyFile]]);
            $address = 'ssl://127.0.0.1:' . $port;
            $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $server = stream_socket_server($address, $errorCode, $errorMessage, $flags, $context);
            if ($server === false) {
                exit(1);
            }
            fwrite(STDOUT, "ready\n");
            fflush(STDOUT);
            $client = @stream_socket_accept($server, 5.0);
            if ($client !== false) {
                fclose($client);
            }
            fclose($server);
            PHP);

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($probe, "Cannot listen: {$errorMessage} ({$errorCode})");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, (string) $port, $certFile, $keyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process, 'Cannot start the TLS test server');
        $this->server = $process;

        stream_set_timeout($pipes[1], 5);
        self::assertSame("ready\n", fgets($pipes[1]), 'The TLS test server did not start');

        return $port;
    }
}
