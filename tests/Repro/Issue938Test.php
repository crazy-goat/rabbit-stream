<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Repro;

use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for GitHub #938: a socket whose descriptor number is at or above
 * FD_SETSIZE cannot be waited on by select(2). The ConnectionException then names the
 * descriptor limit instead of a bare "stream_select failed".
 */
final class Issue938Test extends TestCase
{
    /** Warning text that PHP 8.5 emits for a stream_select() on a descriptor at or above FD_SETSIZE. */
    private const FD_SETSIZE_WARNING = "stream_select(): You MUST recompile PHP with a larger value of FD_SETSIZE.\n"
        . "It is set to 1024, but you have descriptors numbered at least as high as 1104.\n"
        . " --enable-fd-setsize=2048 is recommended, but you may want to set it\n"
        . "to equal the maximum number of open files supported by your system,\n"
        . "in order to avoid seeing this error again at a later date.";

    private const SUMMARY = 'stream_select failed while writing';

    public function testFailureMessageNamesDescriptorLimitForFdSetsizeWarning(): void
    {
        $message = $this->buildFailureMessage(self::FD_SETSIZE_WARNING, self::SUMMARY);

        self::assertStringStartsWith(self::SUMMARY . ': the socket descriptor is at or above FD_SETSIZE', $message);
        self::assertStringContainsString('(select(2) limit, usually 1024)', $message);
        self::assertStringContainsString('lower the number of open descriptors in the process', $message);
        self::assertStringContainsString('PHP warning: stream_select(): You MUST recompile PHP', $message);
        self::assertStringNotContainsString("\n", $message);
    }

    public function testFailureMessageAppendsWarningTextForOtherFailures(): void
    {
        $warning = 'stream_select(): Unable to select [9]: Bad file descriptor (max_fd=7)';

        $message = $this->buildFailureMessage($warning, self::SUMMARY);

        self::assertSame(self::SUMMARY . '. PHP warning: ' . $warning, $message);
        self::assertStringNotContainsString('FD_SETSIZE', $message);
    }

    public function testFailureMessageIsSummaryOnlyWhenNoWarningWasRecorded(): void
    {
        self::assertSame(self::SUMMARY, $this->buildFailureMessage(null, self::SUMMARY));
    }

    public function testSocketAboveFdSetsizeFailsWithMessageNamingDescriptorLimit(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is required to run the high-descriptor probe.');
        }

        // The probe runs in its own process so that the raised open file limit and the
        // 1100 held descriptors do not leak into the rest of the test run.
        $probe = __DIR__ . '/../Support/HighDescriptorProbe.php';
        [$exitCode, $stdout, $stderr] = $this->runProbe($probe);

        if ($exitCode === 77) {
            self::markTestSkipped('The open file limit cannot be raised to 4096 on this system.');
        }
        if ($exitCode === 78) {
            self::markTestSkipped('The probe cannot open 1100 descriptors on this system.');
        }
        if ($exitCode === 79) {
            self::markTestSkipped('The probe cannot create a loopback listener on this system.');
        }

        self::assertSame(
            1,
            $exitCode,
            "The probe must fail with a ConnectionException. stdout: $stdout stderr: $stderr"
        );
        self::assertStringContainsString(
            'stream_select failed while writing: the socket descriptor is at or above FD_SETSIZE',
            $stdout
        );
    }

    /**
     * Build the failure message through the private helper, with the given recorded warning.
     */
    private function buildFailureMessage(?string $recordedWarning, string $summary): string
    {
        $connection = new StreamConnection('127.0.0.1', 1);
        (new \ReflectionProperty($connection, 'lastSelectErrorMessage'))->setValue($connection, $recordedWarning);

        $method = new \ReflectionMethod($connection, 'selectFailureMessage');

        $message = $method->invoke($connection, $summary);
        if (!is_string($message)) {
            self::fail('selectFailureMessage() must return a string.');
        }

        return $message;
    }

    /**
     * @return array{int, string, string} Exit code, stdout and stderr of the probe
     */
    private function runProbe(string $probe): array
    {
        // ulimit runs in the shell, so the child inherits the raised soft limit.
        // The shell exits 77 when the limit cannot be raised, and the probe skips the test
        // when it cannot open 1100 descriptors or create the loopback listener.
        $command = ['/bin/sh', '-c', 'ulimit -n 4096 || exit 77; exec "$0" "$@"', PHP_BINARY, $probe];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            self::fail('proc_open() failed to start the probe.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }
}
