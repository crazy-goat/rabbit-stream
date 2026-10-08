<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CheckStaleSocketsTest extends TestCase
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

    public function testFailsOnForbiddenTokensInSourceAndEnglishDocs(): void
    {
        $root = $this->makeTempRoot([
            'src/Example.php' => "<?php // socket_write\n",
            'docs/en/guide/example.md' => 'Avoid MSG_WAITALL.',
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('src/Example.php:1: forbidden token socket_write', $stderr);
        $this->assertStringContainsString('docs/en/guide/example.md:1: forbidden token MSG_WAITALL', $stderr);
    }

    public function testIgnoresHistoricalDocsDirectories(): void
    {
        $root = $this->makeTempRoot([
            'docs/en/plans/transport.md' => 'socket_recv',
            'docs/en/proof_of_work/transport.md' => 'socket_write',
            'docs/en/guide/current.md' => 'Current docs are clean.',
            'src/StreamConnection.php' => '<?php echo "clean";',
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('No stale ext-sockets references', $stdout);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runGate(string $root): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-stale-sockets.php';
        $process = proc_open(
            [PHP_BINARY, $script, $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-stale-sockets.php');
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
        $dir = sys_get_temp_dir() . '/rabbit-stream-stale-sockets-' . bin2hex(random_bytes(8));
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/docs/en', 0777, true);
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
