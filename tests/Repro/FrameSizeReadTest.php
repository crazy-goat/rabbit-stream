<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Repro;

use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #940: readFrame() must close the connection on a frame size below 2,
 * and a Deliver frame above the fast-path cap must not be copied twice.
 */
final class FrameSizeReadTest extends TestCase
{
    /**
     * A size below 2 has no room for the key. The connection must close, so the
     * body bytes of the bad frame are never parsed as the size of the next frame.
     *
     * @dataProvider tooSmallSizeProvider
     */
    public function testFrameSizeBelowTwoClosesTheConnection(int $size): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();
        $connection = $this->injectSocket(new StreamConnection('127.0.0.1', 5552), $clientSocket);

        // The size field, then one body byte that is not part of a valid frame.
        fwrite($serverSocket, pack('N', $size) . "\x41");

        try {
            $connection->readFrame(1.0);
            self::fail('A frame size below 2 must throw');
        } catch (DeserializationException $exception) {
            self::assertStringContainsString("Frame size {$size} is too small", $exception->getMessage());
        }

        self::assertFalse($connection->isConnected(), 'The connection must close after a frame size below 2');

        fclose($serverSocket);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function tooSmallSizeProvider(): array
    {
        return [
            'size 0' => [0],
            'size 1' => [1],
        ];
    }

    /**
     * A Deliver frame above the fast-path cap is read in two parts. The parts must
     * not be joined into a second full copy, so the peak memory stays near one copy.
     *
     * The frame goes through a pipe, which returns partial reads as a socket does.
     * A child process reads a 64 MiB frame with a 100 MiB memory_limit. The old join
     * peaked near 132 MiB, and the fixed read peaks near 70 MiB. A fatal error cannot
     * be caught in-process, so the child's exit code shows the result.
     */
    public function testLargeDeliverFrameIsNotHeldTwiceInMemory(): void
    {
        // The frame size counts the 2-byte key. 64 MiB is the default Deliver cap.
        $frameSize = 64 * 1024 * 1024;

        [$exitCode, $stdout, $stderr] = $this->readFrameInChild($frameSize, '100M');

        self::assertSame(0, $exitCode, "The child read failed:\n{$stderr}");
        self::assertSame("read\n", $stdout);
    }

    /**
     * @return array{int, string, string} Exit code, stdout and stderr of the child
     */
    private function readFrameInChild(int $frameSize, string $memoryLimit): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = <<<'PHP'
            require $argv[1];
            $connection = new \CrazyGoat\RabbitStream\StreamConnection('127.0.0.1', 5552);
            (new \ReflectionProperty($connection, 'stream'))->setValue($connection, STDIN);
            (new \ReflectionProperty($connection, 'connected'))->setValue($connection, true);
            $frame = $connection->readFrame(10.0);
            echo $frame === null ? "none\n" : "read\n";
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=' . $memoryLimit, '-r', $code, '--', $autoload],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        fwrite($pipes[0], pack('N', $frameSize) . pack('n', 0x0008));
        $bodySize = $frameSize - 2;
        $chunk = str_repeat("\0", 64 * 1024);
        for ($sent = 0; $sent < $bodySize; $sent += strlen($chunk)) {
            $part = $bodySize - $sent >= strlen($chunk) ? $chunk : str_repeat("\0", $bodySize - $sent);
            if (@fwrite($pipes[0], $part) === false) {
                break;
            }
        }
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @return array{resource, resource}
     */
    private function createSocketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'stream_socket_pair() failed');

        return [$pair[0], $pair[1]];
    }

    /**
     * @param resource $stream
     */
    private function injectSocket(StreamConnection $connection, $stream): StreamConnection
    {
        stream_set_blocking($stream, false);
        (new \ReflectionProperty($connection, 'stream'))->setValue($connection, $stream);
        (new \ReflectionProperty($connection, 'connected'))->setValue($connection, true);

        return $connection;
    }
}
