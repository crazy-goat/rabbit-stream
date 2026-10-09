<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class InstallHooksTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rabbit-stream-install-hooks-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testInstallsHooksFromAPlainClone(): void
    {
        $repository = $this->makeClone('plain-clone');

        [$exit, $stdout, $stderr] = $this->runInstaller($repository);

        $hooks = $this->gitPath($repository, 'hooks');
        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('done (1 hook(s))', $stdout);
        $this->assertTrue(is_link($hooks . '/pre-push'));
        $this->assertSame(realpath($repository . '/bin/hooks/pre-push'), realpath($hooks . '/pre-push'));
    }

    public function testInstallsHooksFromALinkedWorktree(): void
    {
        $repository = $this->makeClone('worktree-source');
        $worktree = $this->tempDir . '/linked-worktree';
        $this->runCommand(['git', '-C', $repository, 'worktree', 'add', '--detach', $worktree, 'HEAD']);
        $this->assertFileExists($worktree . '/.git');
        $this->assertFalse(is_dir($worktree . '/.git'));

        [$exit, $stdout, $stderr] = $this->runInstaller($worktree);

        $hooks = $this->gitPath($worktree, 'hooks');
        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('done (1 hook(s))', $stdout);
        $this->assertTrue(is_link($hooks . '/pre-push'));
        $this->assertSame(realpath($worktree . '/bin/hooks/pre-push'), realpath($hooks . '/pre-push'));
    }

    public function testLeavesAnExistingRegularHookUntouched(): void
    {
        $repository = $this->makeClone('custom-hook-clone');
        $hooks = $this->gitPath($repository, 'hooks');
        if (!is_dir($hooks)) {
            mkdir($hooks, 0777, true);
        }
        file_put_contents($hooks . '/pre-push', "#!/bin/sh\necho custom\n");

        [$exit, , $stderr] = $this->runInstaller($repository);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('not a symlink — leaving it untouched', $stderr);
        $this->assertFalse(is_link($hooks . '/pre-push'));
        $this->assertSame("#!/bin/sh\necho custom\n", file_get_contents($hooks . '/pre-push'));
    }

    private function makeClone(string $name): string
    {
        $source = $this->tempDir . '/source-' . $name;
        mkdir($source . '/bin/hooks', 0777, true);
        copy(dirname(__DIR__, 2) . '/bin/install-hooks.sh', $source . '/bin/install-hooks.sh');
        file_put_contents($source . '/bin/hooks/pre-push', "#!/usr/bin/env bash\nexit 0\n");
        chmod($source . '/bin/hooks/pre-push', 0755);
        chmod($source . '/bin/install-hooks.sh', 0755);

        $this->runCommand(['git', 'init', '--quiet', '--initial-branch=main', $source]);
        $this->runCommand(['git', '-C', $source, 'config', 'user.name', 'Install Hooks Test']);
        $this->runCommand(['git', '-C', $source, 'config', 'user.email', 'install-hooks@example.test']);
        $this->runCommand(['git', '-C', $source, 'add', 'bin']);
        $this->runCommand(['git', '-C', $source, 'commit', '--quiet', '-m', 'fixture']);

        $clone = $this->tempDir . '/' . $name;
        $this->runCommand(['git', 'clone', '--quiet', $source, $clone]);

        return $clone;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runInstaller(string $directory): array
    {
        return $this->runCommand(['bash', 'bin/install-hooks.sh'], $directory);
    }

    private function gitPath(string $directory, string $path): string
    {
        [$exit, $stdout, $stderr] = $this->runCommand(
            ['git', 'rev-parse', '--path-format=absolute', '--git-path', $path],
            $directory
        );
        $this->assertSame(0, $exit, $stderr);

        return trim($stdout);
    }

    /** @param list<string> $command
     *  @return array{0: int, 1: string, 2: string}
     */
    private function runCommand(array $command, ?string $directory = null): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        if (!is_resource($process)) {
            $this->fail('Could not start command: ' . implode(' ', $command));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
