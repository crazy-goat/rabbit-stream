<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verification for the #476 suite-coverage gate's config resolution.
 *
 * The `phpunit.xml` → `phpunit.xml.dist` fallback and config-dir-relative path
 * resolution cannot be exercised against the real repository (which tracks a
 * root `phpunit.xml`), so they run against temporary config trees here.
 */
class CheckTestSuitesScriptTest extends TestCase
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

    public function testFallsBackToDistWhenXmlIsAbsent(): void
    {
        $dir = $this->makeTempDir([
            'phpunit.xml.dist' => $this->configWithDirectory('tests/Foo'),
            'tests/Foo/BarTest.php' => "<?php\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($dir);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('1 test file(s) all covered', $stdout);
    }

    public function testPrefersXmlOverDist(): void
    {
        $dir = $this->makeTempDir([
            'phpunit.xml' => $this->configWithDirectory('tests/Foo'),
            // If this file were preferred instead, the gate would exit 2 on the
            // empty allow-list.
            'phpunit.xml.dist' => "<?xml version=\"1.0\"?>\n<phpunit><testsuites /></phpunit>\n",
            'tests/Foo/BarTest.php' => "<?php\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($dir);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('1 test file(s) all covered', $stdout);
    }

    public function testResolvesRelativePathsAgainstConfigDirectory(): void
    {
        // The config lives outside the repository. If `tests/` and the entries
        // resolved against the repo root instead of the config's directory, the
        // uncovered file below would not be discovered and the `tests/Foo`
        // entry would be reported stale.
        $dir = $this->makeTempDir([
            'phpunit.xml' => $this->configWithDirectory('tests/Foo'),
            'tests/Foo/BarTest.php' => "<?php\n",
            'tests/Uncovered/BazTest.php' => "<?php\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($dir);

        $this->assertSame(1, $exit, $stdout);
        $this->assertStringContainsString('tests/Uncovered/BazTest.php', $stderr);
        $this->assertStringNotContainsString('stale', $stderr);
    }

    private function configWithDirectory(string $directory): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <testsuites>
                    <testsuite name="unit">
                        <directory>{$directory}</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runGate(string $argument): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-test-suites.php';
        $process = proc_open(
            [PHP_BINARY, $script, $argument],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-test-suites.php');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @param array<string, string> $files
     */
    private function makeTempDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/rabbit-stream-gate-' . bin2hex(random_bytes(8));
        foreach ($files as $path => $contents) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }

        $real = realpath($dir);
        if ($real === false) {
            $this->fail('Could not create a temporary config directory');
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
