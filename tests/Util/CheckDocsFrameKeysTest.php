<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CheckDocsFrameKeysTest extends TestCase
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

    public function testRejectsUnknownCommandKeyWithPathLineAndToken(): void
    {
        $root = $this->makeTempRoot([
            'docs/en/guide.md' => "A response key `0x8002` is not defined.\n",
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('docs/en/guide.md:1: unknown command key 0x8002', $stderr);
    }

    public function testRejectsOutgoingFrameWithInconsistentSize(): void
    {
        $root = $this->makeTempRoot([
            // This correlated key header contains a 4-byte correlation id; its size field says five bytes follow.
            'docs/en/frames.md' => "Socket -> 000000050001000100000001\n",
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('docs/en/frames.md:1:', $stderr);
        $this->assertStringContainsString('outgoing size field declares 5 bytes; 8 bytes follow', $stderr);
    }

    public function testChecksCorrelationIdWidthAndAllowsRangesMasksAndExplicitExamples(): void
    {
        $root = $this->makeTempRoot([
            'docs/en/guide.md' => implode("\n", [
                'Key range: 0x8000-0x8002; response mask: 0x8000.',
                'An intentionally invalid response key `0x8002`. <!-- docs-frame-keys: ignore 0x8002 -->',
                'Socket <- 00010001',
            ]) . "\n",
        ]);

        [$exit, , $stderr] = $this->runGate($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('<- frame key 0x0001 requires a four-byte CorrelationId', $stderr);
        $this->assertStringNotContainsString('unknown command key', $stderr);
    }

    public function testAcceptsCorrelatedAndUncorrelatedFrames(): void
    {
        $root = $this->makeTempRoot([
            'docs/en/frames.md' => implode("\n", [
                // 4-byte size prefix + key/version + correlation id.
                'Socket -> 000000080001000100000001',
                // Server-push key 0x0003 is uncorrelated and omits the size prefix.
                'Socket <- 0003000100',
            ]) . "\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('Documentation frame/key check passed', $stdout);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runGate(string $root): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-docs-frame-keys.php';
        $process = proc_open(
            [PHP_BINARY, $script, $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-docs-frame-keys.php');
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
        $dir = sys_get_temp_dir() . '/rabbit-stream-frame-keys-' . bin2hex(random_bytes(8));
        mkdir($dir . '/src/Enum', 0777, true);
        mkdir($dir . '/src/Request', 0777, true);
        mkdir($dir . '/src/Response', 0777, true);
        mkdir($dir . '/docs/en', 0777, true);
        file_put_contents($dir . '/src/Enum/KeyEnum.php', <<<'PHP'
            <?php
            enum KeyEnum: int
            {
                case EXAMPLE = 0x0001;
                case PUBLISH_CONFIRM = 0x0003;
                case EXAMPLE_RESPONSE = 0x8001;
            }
            PHP);
        file_put_contents($dir . '/src/Request/ExampleRequestV1.php', <<<'PHP'
            <?php
            class ExampleRequestV1 implements CorrelationInterface
            {
                public static function getKey(): int
                {
                    return KeyEnum::EXAMPLE->value;
                }
            }
            PHP);
        file_put_contents($dir . '/src/Response/ExampleResponseV1.php', <<<'PHP'
            <?php
            class ExampleResponseV1 implements CorrelationInterface
            {
                public static function getKey(): int
                {
                    return KeyEnum::EXAMPLE_RESPONSE->value;
                }
            }
            PHP);
        file_put_contents($dir . '/src/Response/PublishConfirmResponseV1.php', <<<'PHP'
            <?php
            class PublishConfirmResponseV1
            {
                public static function getKey(): int
                {
                    return KeyEnum::PUBLISH_CONFIRM->value;
                }
            }
            PHP);

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
