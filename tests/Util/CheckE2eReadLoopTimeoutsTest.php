<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CheckE2eReadLoopTimeoutsTest extends TestCase
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

    public function testFailsOnReadLoopCallWithoutTimeout(): void
    {
        $root = $this->makeTempRoot([
            'tests/E2E/SomeE2ETest.php' => "<?php\n\$connection->readLoop(maxFrames: 1);\n",
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tests/E2E/SomeE2ETest.php:2: readLoop() call without a timeout', $stderr);
    }

    public function testFailsOnBareReadLoopCallAndOnMultiLineCall(): void
    {
        $root = $this->makeTempRoot([
            'tests/E2E/BareE2ETest.php' => "<?php\n\$connection->readLoop();\n",
            'tests/E2E/MultiLineE2ETest.php' => <<<'PHP'
                <?php
                $connection->readLoop(
                    maxFrames: 1,
                );
                PHP,
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tests/E2E/BareE2ETest.php:2: readLoop() call without a timeout', $stderr);
        $this->assertStringContainsString(
            'tests/E2E/MultiLineE2ETest.php:2: readLoop() call without a timeout',
            $stderr
        );
    }

    public function testFailsOnExplicitNullTimeout(): void
    {
        $root = $this->makeTempRoot([
            'tests/E2E/NullE2ETest.php' => "<?php\n\$connection->readLoop(maxFrames: 1, timeout: null);\n",
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tests/E2E/NullE2ETest.php:2: readLoop() call without a timeout', $stderr);
    }

    public function testAcceptsReadLoopCallsWithNamedTimeout(): void
    {
        $root = $this->makeTempRoot([
            'tests/E2E/BoundedE2ETest.php' => <<<'PHP'
                <?php
                $connection->readLoop(maxFrames: 1, timeout: 5.0);
                $connection->readLoop(maxFrames: 1, timeout: $deadline - microtime(true));
                $connection->readLoop(maxFrames: 1, timeout: max(0.1, $deadline - microtime(true)));
                $connection->readLoop(
                    maxFrames: 1,
                    timeout: 2.0,
                );
                PHP,
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('All readLoop() calls in tests/E2E pass a timeout.', $stdout);
    }

    public function testIgnoresCommentsStringsDeclarationsAndFilesOutsideE2e(): void
    {
        $root = $this->makeTempRoot([
            'tests/E2E/CommentE2ETest.php' => <<<'PHP'
                <?php
                // $connection->readLoop(maxFrames: 1);
                /** readLoop(maxFrames: 1) in a docblock */
                $message = 'call readLoop(maxFrames: 1) later';
                PHP,
            'tests/Client/UnitTest.php' => "<?php\n\$connection->readLoop(maxFrames: 1);\n",
            'src/Connection.php' => "<?php\nfunction readLoop(int \$maxFrames) {}\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($root);

        $this->assertSame(0, $exit, $stderr);
    }

    public function testUsageErrorWhenRootHasNoE2eDirectory(): void
    {
        $root = $this->makeTempRoot(['src/Example.php' => "<?php\n"]);
        rmdir($root . '/tests/E2E');

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Usage: php bin/check-e2e-readloop-timeouts.php', $stderr);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runGate(string $root): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-e2e-readloop-timeouts.php';
        $process = proc_open(
            [PHP_BINARY, $script, $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-e2e-readloop-timeouts.php');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @param array<string, string> $files */
    private function makeTempRoot(array $files): string
    {
        $dir = sys_get_temp_dir() . '/rabbit-stream-e2e-readloop-' . bin2hex(random_bytes(8));
        mkdir($dir . '/tests/E2E', 0777, true);
        mkdir($dir . '/src', 0777, true);
        foreach ($files as $path => $contents) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }

        $real = realpath($dir);
        if ($real === false) {
            $this->fail('Could not create a temporary repository root');
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
