<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Regression test for #472: the "wait for healthy" loop in `run-e2e.sh` was
 * unbounded and depended on host `python3` plus the JSON shape of
 * `docker compose ps --format json`. When the broker never became healthy (or
 * compose emitted an array, or `python3` was missing) the script printed dots
 * forever instead of failing.
 *
 * The script runs against fake `docker`, `sleep`, `curl` and `vendor/bin/phpunit`
 * executables on a private PATH, so no Docker or RabbitMQ is needed. The health
 * status and the `docker compose ps -q` answer come from the fake `docker`.
 */
class RunE2eHealthWaitTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    public function testRunsTheSuiteWhenTheBrokerBecomesHealthy(): void
    {
        $result = $this->runE2e('healthy');

        $this->assertFalse($result['timedOut'], 'run-e2e.sh did not finish');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('FAKE-PHPUNIT-RAN', $result['stdout']);
    }

    public function testFailsWithDiagnosticsWhenTheBrokerNeverBecomesHealthy(): void
    {
        $result = $this->runE2e('starting');

        $this->assertFalse($result['timedOut'], 'run-e2e.sh did not finish: the wait loop is unbounded');
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('ERROR: RabbitMQ is not healthy', $result['stderr']);
        $this->assertStringContainsString("last status: 'starting'", $result['stderr']);
        $this->assertStringContainsString('FAKE-RABBITMQ-LOG', $result['stderr']);
        $this->assertStringNotContainsString('FAKE-PHPUNIT-RAN', $result['stdout']);
    }

    public function testFailsWhenComposeStartedNoContainer(): void
    {
        $result = $this->runE2e('healthy', psQ: '');

        $this->assertFalse($result['timedOut'], 'run-e2e.sh did not finish');
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString("started no 'rabbitmq' container", $result['stderr']);
        $this->assertStringNotContainsString('FAKE-PHPUNIT-RAN', $result['stdout']);
    }

    public function testDoesNotInvokeHostPython3(): void
    {
        // A failing `python3` shadows any real one. The old pipeline piped
        // `docker compose ps --format json` through it, so this scenario only
        // finished once the dependency was removed.
        $dir = $this->makeFixture();
        $result = $this->runE2eIn($dir, 'fakecid', 'healthy');

        $this->assertFalse($result['timedOut'], 'run-e2e.sh did not finish without python3');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('FAKE-PHPUNIT-RAN', $result['stdout']);
        $this->assertFileDoesNotExist(
            $dir . '/python3-was-called',
            'run-e2e.sh invoked the host python3'
        );
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string, timedOut: bool}
     */
    private function runE2e(string $health, string $psQ = 'fakecid', int $timeoutSeconds = 30): array
    {
        $dir = $this->makeFixture();

        return $this->runE2eIn($dir, $psQ, $health, $timeoutSeconds);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string, timedOut: bool}
     */
    private function runE2eIn(string $dir, string $psQ, string $health, int $timeoutSeconds = 30): array
    {
        $stdoutFile = $dir . '/stdout.txt';
        $stderrFile = $dir . '/stderr.txt';

        $process = proc_open(
            ['bash', $dir . '/run-e2e.sh'],
            [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
            $dir,
            [
                'PATH' => $dir . '/bin:/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: '/tmp',
                'FAKE_LOG' => $dir . '/docker.log',
                'FAKE_HEALTH' => $health,
                'FAKE_PS_Q' => $psQ,
                'E2E_HEALTH_RETRIES' => '3',
                'E2E_HEALTH_INTERVAL' => '0',
            ]
        );
        if (!is_resource($process)) {
            $this->fail('Could not start run-e2e.sh');
        }

        $exitCode = 0;
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                proc_close($process);
                break;
            }
            usleep(50_000);
        }
        if (!$timedOut) {
            proc_close($process);
        }

        return [
            'exit' => $exitCode,
            'stdout' => (string) file_get_contents($stdoutFile),
            'stderr' => (string) file_get_contents($stderrFile),
            'timedOut' => $timedOut,
        ];
    }

    private function makeFixture(): string
    {
        $dir = $this->makeTempDir([
            'run-e2e.sh' => (string) file_get_contents(dirname(__DIR__, 2) . '/run-e2e.sh'),
            'vendor/bin/phpunit' => "#!/usr/bin/env bash\necho FAKE-PHPUNIT-RAN\nexit 0\n",
            'bin/docker' => <<<'SH'
                #!/usr/bin/env bash
                echo "$*" >> "$FAKE_LOG"
                if [ "$1" = compose ]; then
                  shift
                  case "$1" in
                    up|down) exit 0 ;;
                    ps)
                      if [ -n "$FAKE_PS_Q" ]; then echo "$FAKE_PS_Q"; fi
                      exit 0 ;;
                    logs) echo FAKE-RABBITMQ-LOG; exit 0 ;;
                  esac
                fi
                if [ "$1" = inspect ]; then echo "$FAKE_HEALTH"; exit 0; fi
                exit 0
                SH,
            'bin/sleep' => "#!/usr/bin/env bash\nexit 0\n",
            'bin/curl' => "#!/usr/bin/env bash\nexit 0\n",
            'bin/python3' => "#!/usr/bin/env bash\ntouch \"\$(dirname \"\$0\")/../python3-was-called\"\nexit 127\n",
        ]);
        $executables = ['run-e2e.sh', 'vendor/bin/phpunit', 'bin/docker', 'bin/sleep', 'bin/curl', 'bin/python3'];
        foreach ($executables as $file) {
            if (chmod($dir . '/' . $file, 0755) === false) {
                $this->fail('Could not make ' . $file . ' executable');
            }
        }

        return $dir;
    }

    /**
     * @param array<string, string> $files
     */
    private function makeTempDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/rabbit-stream-e2e-' . bin2hex(random_bytes(8));
        foreach ($files as $path => $contents) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }

        $real = realpath($dir);
        if ($real === false) {
            $this->fail('Could not create a temporary fixture directory');
        }
        $this->tempDirs[] = $real;

        return $real;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
