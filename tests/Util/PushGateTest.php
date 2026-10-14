<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PushGateTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rabbit-stream-push-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/bin', 0777, true);
        mkdir($this->tempDir . '/run', 0777, true);
        $this->runGit(['init', '-b', 'main']);
        $this->runGit(['init', '--bare', $this->tempDir . '/origin.git']);
        $this->runGit(['remote', 'add', 'origin', $this->tempDir . '/origin.git']);
        $this->runGit(['config', 'user.name', 'Test']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->commit('main.txt', 'default branch', 'default branch commit');
        $this->runGit(['push', '-u', 'origin', 'main']);
        $this->runGit(['checkout', '-b', 'issue-42']);
        $this->commit('change.txt', 'issue change', 'feat: preserve issue title (#42)');
        $this->runGit(['checkout', 'main']);
        $this->commit('main-update.txt', 'default branch update', 'docs: update default branch');
        $this->runGit(['push', 'origin', 'main']);
        $this->runGit(['checkout', 'issue-42']);
        $this->runGit(['fetch', 'origin']);
        $mergeSubject = "Merge remote-tracking branch 'origin/main' into issue-42";
        $this->runGit(['merge', '--no-ff', '-m', $mergeSubject, 'origin/main']);
        $this->runGit(['push', '-u', 'origin', 'issue-42']);
        $this->createGhStub();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testCreatesPullRequestWithFirstNonMergeCommitSubject(): void
    {
        $head = trim($this->runGit(['rev-list', '--parents', '-n', '1', 'HEAD']));
        $this->assertCount(3, explode(' ', $head), 'HEAD must be a merge commit with two parents.');
        $this->assertSame(
            "Merge remote-tracking branch 'origin/main' into issue-42",
            trim($this->runGit(['show', '-s', '--format=%s', 'HEAD']))
        );

        [$exit, $stdout, $stderr, $calls] = $this->runPush();

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringContainsString('--title feat: preserve issue title (#42)', $calls);
        $this->assertStringNotContainsString('Merge remote-tracking branch', $calls);
        $this->assertSame("123\n", file_get_contents($this->tempDir . '/run/pr'));
    }

    public function testFallsBackToBranchCommitWhenDefaultBranchIsNotAnAncestor(): void
    {
        $this->runGit(['checkout', '--orphan', 'unrelated']);
        $this->runGit(['rm', '-rf', '.']);
        $this->commit('unrelated.txt', 'unrelated change', 'fix: use branch subject (#42)');
        $this->runGit(['branch', '-M', 'issue-42']);
        $this->runGit(['push', '--force', 'origin', 'issue-42']);

        [$exit, , $stderr, $calls] = $this->runPush();

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('--title fix: use branch subject (#42)', $calls);
    }

    private function createGhStub(): void
    {
        file_put_contents($this->tempDir . '/bin/gh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$GH_CALLS"
case "$1" in
    pr)
        case "$2" in
            list)
                if [[ -f "$GH_PR_EXISTS" ]]; then printf '123\n'; else printf '\n'; fi
                ;;
            create) touch "$GH_PR_EXISTS"; printf 'https://github.com/owner/repo/pull/123\n' ;;
            *) exit 2 ;;
        esac
        ;;
    *) exit 2 ;;
esac
BASH);
        chmod($this->tempDir . '/bin/gh', 0755);
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function runPush(): array
    {
        $callsPath = $this->tempDir . '/calls';
        $process = proc_open(
            ['bash', dirname(__DIR__, 2) . '/.tyci/workflows/issue-to-merge/checks/push.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tempDir,
            [
                'PATH' => $this->tempDir . '/bin:' . (getenv('PATH') ?: ''),
                'GH_CALLS' => $callsPath,
                'GH_PR_EXISTS' => $this->tempDir . '/pr-exists',
                'TYCI_BRANCH' => 'issue-42',
                'TYCI_DEFAULT_BRANCH' => 'main',
                'TYCI_ISSUE' => '42',
                'TYCI_REPO' => 'owner/repo',
                'TYCI_RUN_DIR' => $this->tempDir . '/run',
            ]
        );
        if (!is_resource($process)) {
            $this->fail('Could not start checks/push.sh');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr, (string) file_get_contents($callsPath)];
    }

    /** @param list<string> $arguments */
    private function runGit(array $arguments): string
    {
        $process = proc_open(
            array_merge(['git'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tempDir
        );
        if (!is_resource($process)) {
            $this->fail('Could not start git');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertSame(0, $exit, $stderr . $stdout);
        return $stdout;
    }

    private function commit(string $path, string $contents, string $subject): void
    {
        file_put_contents($this->tempDir . '/' . $path, $contents);
        $this->runGit(['add', $path]);
        $this->runGit(['commit', '-m', $subject]);
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
