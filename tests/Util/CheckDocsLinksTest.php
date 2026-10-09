<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CheckDocsLinksTest extends TestCase
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

    public function testAcceptsExistingFilesAndHeadingAnchors(): void
    {
        $docs = $this->makeDocs([
            'guide.md' => "# Existing Heading\n\n"
                . "See [another page](pages/target.md#target-heading) and [this page](#existing-heading).\n",
            'pages/target.md' => "# Target Heading\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($docs);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('All relative links', $stdout);
    }

    public function testReportsMissingFileEvenWhenLinkHasAnchor(): void
    {
        $docs = $this->makeDocs([
            'guide.md' => "See [missing page](missing.md#section).\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing.md#section', $stderr);
    }

    public function testReportsMissingAnchorInExistingFile(): void
    {
        $docs = $this->makeDocs([
            'guide.md' => "See [existing page](target.md#missing-section).\n",
            'target.md' => "# Existing Section\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing anchor #missing-section in target.md', $stderr);
    }

    /** @return array{int, string, string} */
    private function runGate(string $docs): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-docs-links.php';
        $process = proc_open(
            [PHP_BINARY, $script, $docs],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-docs-links.php');
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
    private function makeDocs(array $files): string
    {
        $dir = sys_get_temp_dir() . '/rabbit-stream-docs-links-' . bin2hex(random_bytes(8));
        foreach ($files as $path => $contents) {
            $fullPath = $dir . '/' . $path;
            if (!is_dir(dirname($fullPath))) {
                mkdir(dirname($fullPath), 0777, true);
            }
            file_put_contents($fullPath, $contents);
        }

        $realPath = realpath($dir);
        if ($realPath === false) {
            $this->fail('Could not create temporary docs');
        }
        $this->tempDirs[] = $realPath;

        return $realPath;
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
