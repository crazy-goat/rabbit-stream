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
 * PHP reports that reason only as warnings, and OpenSSL reports the key errors in its queue.
 * The test runs a real TLS listener in a child process. The certificate is for broker.test and
 * the client connects to 127.0.0.1, so the peer name check fails.
 */
final class TlsConnectFailureTest extends TestCase
{
    private const PASSPHRASE = 'secret-pass-928';

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

        // setUp() skips before it creates the directory, so the path is empty then.
        // Delete only a directory that this test created in the temp directory.
        if ($this->dir === '' || !str_starts_with($this->dir, sys_get_temp_dir() . '/')) {
            return;
        }

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

    public function testWrongPassphraseReasonIsInMessageWithoutThePassphrase(): void
    {
        $port = $this->startTlsServer();
        $this->writeEncryptedClientKey();

        $connection = new StreamConnection(
            '127.0.0.1',
            $port,
            socketTimeout: 3.0,
            tls: $this->wrongPassphraseConfig()
        );

        try {
            $connection->connect();
            self::fail('Expected ConnectionException: the key passphrase is wrong');
        } catch (ConnectionException $e) {
            $message = $e->getMessage();
            self::assertStringStartsWith("Cannot connect to ssl://127.0.0.1:{$port}: ", $message);
            // The OpenSSL reason is only in the error queue, not in a PHP warning.
            self::assertStringContainsString('bad decrypt', $message);
            self::assertStringNotContainsString(self::PASSPHRASE, $message);
            self::assertStringNotContainsString("\n", $message);
        }
    }

    public function testFailedTlsConnectClearsTheOpenSslErrorQueue(): void
    {
        $port = $this->startTlsServer();
        $this->writeEncryptedClientKey();

        $connection = new StreamConnection(
            '127.0.0.1',
            $port,
            socketTimeout: 3.0,
            tls: $this->wrongPassphraseConfig()
        );

        try {
            $connection->connect();
            self::fail('Expected ConnectionException: the key passphrase is wrong');
        } catch (ConnectionException) {
            // Expected: the reason is checked in the test above.
        }

        self::assertFalse(openssl_error_string(), 'A failed TLS connect left OpenSSL errors in the queue');
    }

    public function testOpenSslErrorLeftBeforeConnectIsNotReportedAsItsReason(): void
    {
        // A closed port: the connect fails with "Connection refused", and no TLS error occurs.
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($probe, "Cannot listen: {$errorMessage} ({$errorCode})");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        // The application leaves an OpenSSL error in the queue before it calls connect().
        self::assertFalse(openssl_pkey_get_private('not a key'));

        $connection = new StreamConnection(
            '127.0.0.1',
            $port,
            socketTimeout: 1.0,
            tls: new TlsConfig()
        );

        try {
            $connection->connect();
            self::fail('Expected ConnectionException: the port is closed');
        } catch (ConnectionException $e) {
            self::assertStringStartsWith("Cannot connect to ssl://127.0.0.1:{$port}: ", $e->getMessage());
            self::assertStringNotContainsString('error:', $e->getMessage());
        }
    }

    private function wrongPassphraseConfig(): TlsConfig
    {
        return new TlsConfig(
            cafile: $this->dir . '/cert.pem',
            localCert: $this->dir . '/cert.pem',
            localPk: $this->dir . '/ckey.pem',
            passphrase: 'wrong-' . self::PASSPHRASE,
        );
    }

    /**
     * Write a client key that is encrypted with PASSPHRASE. The test connects with another
     * passphrase, so PHP cannot load the key.
     */
    private function writeEncryptedClientKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'Cannot create a client key');
        self::assertTrue(
            openssl_pkey_export_to_file($key, $this->dir . '/ckey.pem', self::PASSPHRASE),
            'Cannot write the client key'
        );
        $this->drainOpenSslErrors();
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
        // Key generation leaves OpenSSL errors in the queue. Clear them, so that a test
        // sees only the errors of its own connect.
        $this->drainOpenSslErrors();

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

    private function drainOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Discard each queued error.
        }
    }
}
