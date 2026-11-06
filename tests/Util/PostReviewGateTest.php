<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PostReviewGateTest extends TestCase
{
    private string $tempDir;

    /** @var array<string, string> */
    private array $commits = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rabbit-stream-post-review-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/bin', 0777, true);
        mkdir($this->tempDir . '/review', 0777, true);
        mkdir($this->tempDir . '/repo', 0777, true);
        $this->runGit(['init', '-b', 'main']);
        $this->runGit(['config', 'user.name', 'Test']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->commit('base.txt', 'base', 'chore: base commit');
        $this->runGit(['checkout', '-b', 'issue-904']);
        $this->commit('change.txt', 'issue change', 'fix: the change (#904)');
        $this->commits['reviewed'] = $this->head();
        $this->runGit(['checkout', 'main']);
        $this->commit('main-update.txt', 'default update', 'docs: default update');
        $this->runGit(['checkout', 'issue-904']);
        $this->runGit(['merge', '--no-ff', '-m', "Merge remote-tracking branch 'main' into issue-904", 'main']);
        $this->commits['head'] = $this->head();
        $this->createGhStub();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testGoodReportIsPosted(): void
    {
        $this->writeReport("ACCEPT\nReviewed: " . $this->commits['head'] . "\n\n- no findings\n");

        [$exit, $stdout, $stderr, $calls] = $this->runPostReview();

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringContainsString('pr review 42 -R owner/repo --comment', $calls);
    }

    public function testChangesReportWithReviewedAncestorIsPosted(): void
    {
        $this->writeReport("CHANGES\nReviewed: " . $this->commits['reviewed'] . "\n\n- file.php:1 wrong\n");

        [$exit, $stdout, , $calls] = $this->runPostReview();

        $this->assertSame(0, $exit);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringContainsString('pr review 42', $calls);
    }

    public function testReportWithoutReviewedLineFails(): void
    {
        $this->writeReport("ACCEPT\n\n- no findings\n");

        [$exit, $stdout, $stderr, $calls] = $this->runPostReview();

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("fail\n", $stdout);
        $this->assertStringContainsString('wrong format', $stderr);
        $this->assertStringContainsString('pr review 42', $calls, 'A malformed review is still posted.');
    }

    public function testReportWithWrongShaFails(): void
    {
        $this->writeReport("ACCEPT\nReviewed: " . str_repeat('a', 40) . "\n");

        [, $stdout, $stderr, $calls] = $this->runPostReview();

        $this->assertSame("fail\n", $stdout);
        $this->assertStringContainsString('ACCEPT', $stderr);
        $this->assertStringContainsString('pr review 42', $calls, 'A malformed review is still posted.');
    }

    /** @return iterable<string, array{string}> */
    public static function nonFortyHexShaProvider(): iterable
    {
        yield 'short sha' => ['Reviewed: ' . 'f0a1b2c'];
        yield 'upper-case sha' => ['Reviewed: ' . strtoupper('0123456789abcdef0123456789abcdef01234567')];
        yield 'too long' => ['Reviewed: ' . str_repeat('b', 41)];
        yield 'too short' => ['Reviewed: ' . str_repeat('c', 39)];
        yield 'not hex' => ['Reviewed: ' . str_repeat('g', 40)];
        yield 'no space after colon' => ['Reviewed:' . str_repeat('d', 40)];
        yield 'trailing text' => ['Reviewed: ' . str_repeat('e', 40) . ' (round 1)'];
    }

    /** @dataProvider nonFortyHexShaProvider */
    public function testNonFortyHexShaFails(string $line2): void
    {
        $this->writeReport("ACCEPT\n" . $line2 . "\n");

        [, $stdout, , $calls] = $this->runPostReview();

        $this->assertSame("fail\n", $stdout);
        $this->assertStringContainsString('pr review 42', $calls, 'A malformed review is still posted.');
    }

    /** @return iterable<string, array{string}> */
    public static function badFirstLineProvider(): iterable
    {
        yield 'lower case' => ['accept'];
        yield 'other word' => ['APPROVE'];
        yield 'trailing space' => ['ACCEPT '];
        yield 'empty first line' => [''];
    }

    /** @dataProvider badFirstLineProvider */
    public function testBadFirstLineFails(string $line1): void
    {
        $this->writeReport($line1 . "\nReviewed: " . $this->commits['head'] . "\n");

        [, $stdout, , $calls] = $this->runPostReview();

        $this->assertSame("fail\n", $stdout);
        $this->assertStringContainsString('pr review 42', $calls, 'A malformed review is still posted.');
    }

    /** @return iterable<string, array{string}> */
    public static function crlfReportProvider(): iterable
    {
        yield 'CRLF on line 1' => ["ACCEPT\r\nReviewed: "];
        yield 'CRLF on line 2' => ["ACCEPT\nReviewed: "];
    }

    /** @dataProvider crlfReportProvider */
    public function testCrlfLineEndingsFail(string $prefix): void
    {
        $this->writeReport($prefix . $this->commits['head'] . "\r\n");

        [, $stdout, , $calls] = $this->runPostReview();

        $this->assertSame("fail\n", $stdout);
        $this->assertStringContainsString('pr review 42', $calls, 'A malformed review is still posted.');
    }

    public function testReviewedCommitWithNonMergeCommitAfterItFails(): void
    {
        $this->runGit(['checkout', 'issue-904']);
        $this->commit('later.txt', 'later', 'fix: later change (#904)');
        $this->writeReport("ACCEPT\nReviewed: " . $this->commits['head'] . "\n");

        [, $stdout, , $calls] = $this->runPostReview();

        $this->assertSame("fail\n", $stdout);
        $this->assertStringContainsString('pr review 42', $calls, 'The review is still posted.');
    }

    private function writeReport(string $contents): void
    {
        file_put_contents($this->tempDir . '/review/report.md', $contents);
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
            review) exit 0 ;;
            *) exit 2 ;;
        esac
        ;;
    *) exit 2 ;;
esac
BASH);
        chmod($this->tempDir . '/bin/gh', 0755);
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function runPostReview(): array
    {
        $callsPath = $this->tempDir . '/calls';
        $process = proc_open(
            ['bash', dirname(__DIR__, 2) . '/.tyci/workflows/issue-to-merge/checks/post_review.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tempDir . '/repo',
            [
                'PATH' => $this->tempDir . '/bin:' . (getenv('PATH') ?: ''),
                'GH_CALLS' => $callsPath,
                'TYCI_PR' => '42',
                'TYCI_REPO' => 'owner/repo',
                'TYCI_REVIEW_DIR' => $this->tempDir . '/review',
                'TYCI_BRANCH' => 'issue-904',
                'TYCI_DEFAULT_BRANCH' => 'main',
            ]
        );
        if (!is_resource($process)) {
            $this->fail('Could not start checks/post_review.sh');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr, (string) @file_get_contents($callsPath)];
    }

    private function head(): string
    {
        return trim($this->runGit(['rev-parse', 'HEAD']));
    }

    /** @param list<string> $arguments */
    private function runGit(array $arguments): string
    {
        $process = proc_open(
            array_merge(['git'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tempDir . '/repo'
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
        file_put_contents($this->tempDir . '/repo/' . $path, $contents);
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
