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
     * A Deliver frame above the fast-path cap must not hold a second full copy of
     * the frame. The fixed read path should peak as the fast path does, which reads
     * the same frame in one piece. The old join peaked at about twice the frame size.
     *
     * The test compares the peak of a slow-path child (default caps) with the peak of a
     * fast-path child. Both children run with no memory_limit. The children report
     * memory_get_peak_usage(false), which counts the memory that PHP uses. The real
     * size from memory_get_peak_usage(true) depends on the allocator: on Linux the
     * fast path gave 96 MiB in one run and 128 MiB in the next, so the test was
     * flaky (#1071). With a 64 MiB frame, the fixed path and the fast path peak at
     * about 65.5 MiB, and the old join peaked at about 129.5 MiB. The frame goes
     * through a pipe, which returns partial reads as a socket does.
     */
    public function testLargeDeliverFrameIsNotHeldTwiceInMemory(): void
    {
        // The frame size counts the 2-byte key. 64 MiB is the default Deliver cap.
        $frameSize = 64 * 1024 * 1024;
        $slackBytes = 4 * 1024 * 1024;

        $slowPeak = $this->readPeakInChild($frameSize, 'default');
        $fastPeak = $this->readPeakInChild($frameSize, 'fast');

        $message = sprintf(
            'Slow path peak: %.1f MiB; fast path peak: %.1f MiB',
            $slowPeak / 1048576,
            $fastPeak / 1048576,
        );

        // A peak below the frame size means that the measure does not see the frame
        // (for example with USE_ZEND_ALLOC=0), so the comparison would prove nothing.
        self::assertGreaterThanOrEqual($frameSize, $fastPeak, $message);
        self::assertLessThanOrEqual($fastPeak + $slackBytes, $slowPeak, $message);
    }

    /**
     * Read one frame of $frameSize bytes in a child process and return its peak memory.
     *
     * @param string $mode 'default' keeps the default caps (slow path); 'fast' raises the
     *                     frame cap to $frameSize (one-piece read)
     * @return int Peak memory in bytes, from memory_get_peak_usage(false) in the child
     */
    private function readPeakInChild(int $frameSize, string $mode): int
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = <<<'PHP'
            require $argv[1];
            $connection = new \CrazyGoat\RabbitStream\StreamConnection('127.0.0.1', 5552);
            (new \ReflectionProperty($connection, 'stream'))->setValue($connection, STDIN);
            (new \ReflectionProperty($connection, 'connected'))->setValue($connection, true);
            if ($argv[2] === 'fast') {
                $connection->setMaxFrameSize((int) $argv[3]);
            }
            $frame = $connection->readFrame(10.0);
            echo $frame === null ? "none\n" : 'read peak=' . memory_get_peak_usage(false) . "\n";
            PHP;

        $process = proc_open(
            [
                PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'display_errors=stderr',
                '-r', $code, '--', $autoload, $mode, (string) $frameSize,
            ],
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
        $exitCode = proc_close($process);

        $context = "exit={$exitCode}\nstdout:\n{$stdout}\nstderr:\n{$stderr}";
        self::assertSame(0, $exitCode, "The child read failed. {$context}");
        $matched = preg_match('/^read peak=(\d+)$/', trim($stdout), $matches);
        self::assertSame(1, $matched, "Unexpected child output. {$context}");

        return (int) $matches[1];
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
