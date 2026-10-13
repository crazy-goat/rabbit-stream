<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class IssueDoneGateTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rabbit-stream-issue-done-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/bin', 0777, true);
        file_put_contents($this->tempDir . '/bin/gh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$GH_CALLS"
case "$*" in
    'issue view 42 -R owner/repo --json state,labels,author,assignees')
        printf '%s\n' "$ISSUE_JSON"
        ;;
    'api user --jq .login')
        printf '%s\n' "$GH_LOGIN"
        ;;
    'api -i repos/owner/repo/collaborators/author/permission')
        printf 'HTTP/1.1 200 OK\r\n\r\n{"permission":"write"}\n'
        ;;
    'issue edit 42 -R owner/repo --add-assignee runner')
        ;;
    *)
        echo "unexpected gh call: $*" >&2
        exit 2
        ;;
esac
BASH);
        chmod($this->tempDir . '/bin/gh', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testAssignsUnassignedIssueBeforeReturningGo(): void
    {
        [$exit, $stdout, $stderr, $calls] = $this->runGate($this->issueJson([]));

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("go\n", $stdout);
        $this->assertStringContainsString('issue edit 42 -R owner/repo --add-assignee runner', $calls);
        $this->assertLessThan(
            strpos($calls, 'issue edit'),
            strpos($calls, 'api user')
        );
    }

    public function testAllowsAndReassignsIssueAlreadyAssignedToCurrentAccount(): void
    {
        [$exit, $stdout, $stderr, $calls] = $this->runGate($this->issueJson(['runner']));

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("go\n", $stdout);
        $this->assertStringContainsString('issue edit 42 -R owner/repo --add-assignee runner', $calls);
    }

    public function testSkipsIssueAssignedToAnotherAccountWithoutWriting(): void
    {
        [$exit, $stdout, $stderr, $calls] = $this->runGate($this->issueJson(['someone-else']));

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("skip\n", $stdout);
        $this->assertStringNotContainsString('issue edit', $calls);
        $this->assertStringContainsString('api user --jq .login', $calls);
    }

    /** @param list<string> $assignees */
    private function issueJson(array $assignees): string
    {
        return json_encode([
            'state' => 'OPEN',
            'labels' => [['name' => 'accepted']],
            'author' => ['login' => 'author'],
            'assignees' => array_map(static fn (string $login): array => ['login' => $login], $assignees),
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function runGate(string $issueJson): array
    {
        $callsPath = $this->tempDir . '/calls';
        $script = dirname(__DIR__, 2) . '/.tyci/workflows/issue-to-merge/checks/issue_done.sh';
        $process = proc_open(
            ['bash', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'PATH' => $this->tempDir . '/bin:' . (getenv('PATH') ?: ''),
                'GH_CALLS' => $callsPath,
                'GH_LOGIN' => 'runner',
                'ISSUE_JSON' => $issueJson,
                'TYCI_ISSUE' => '42',
                'TYCI_REPO' => 'owner/repo',
            ]
        );
        if (!is_resource($process)) {
            $this->fail('Could not start checks/issue_done.sh');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr, (string) file_get_contents($callsPath)];
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
