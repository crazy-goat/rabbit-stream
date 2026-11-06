<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verification for bin/check-api-signature.php (#918).
 *
 * Each test builds a temporary git repository. The `src/` tree of the first
 * commit is tagged `v1.0.0`. The working tree then holds the "current" `src/`.
 * The fixtures are PHP strings, so PHPStan, PHPCS and the autoloader never see them.
 */
class CheckApiSignatureTest extends TestCase
{
    private const CLIENT_V1 = <<<'PHP'
        <?php

        namespace CrazyGoat\RabbitStream\Api;

        class Client
        {
            public const LIMIT = 10;

            public function send(string $stream, int $count = 1): bool
            {
                return true;
            }

            public function close(): void
            {
            }
        }
        PHP;

    private const HANDLER_V1 = <<<'PHP'
        <?php

        namespace CrazyGoat\RabbitStream\Api;

        interface Handler
        {
            public function handle(): void;
        }
        PHP;

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

    public function testPassesWhenTheCurrentTreeEqualsTheTag(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1, 'Api/Handler.php' => self::HANDLER_V1],
            ['Api/Client.php' => self::CLIENT_V1, 'Api/Handler.php' => self::HANDLER_V1],
        );

        [$exit, $stdout, $stderr] = $this->runCheck($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('0 break(s), 0 allowed.', $stdout);
    }

    public function testFailsWhenAPublicMethodIsRemoved(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => $this->withoutMethod(self::CLIENT_V1, 'close')],
        );

        [$exit, , $stderr] = $this->runCheck($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('BREAK removed M|CrazyGoat\RabbitStream\Api\Client::close', $stderr);
    }

    public function testFailsWhenAParameterTypeChanges(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => str_replace('send(string $stream', 'send(int $stream', self::CLIENT_V1)],
        );

        [$exit, , $stderr] = $this->runCheck($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('BREAK changed M|CrazyGoat\RabbitStream\Api\Client::send', $stderr);
    }

    public function testFailsWhenADefaultValueChanges(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => str_replace('int $count = 1', 'int $count = 2', self::CLIENT_V1)],
        );

        [$exit, , $stderr] = $this->runCheck($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('BREAK changed M|CrazyGoat\RabbitStream\Api\Client::send', $stderr);
    }

    public function testFailsWhenAnInterfaceGainsAMethod(): void
    {
        $handlerV2 = str_replace(
            'public function handle(): void;',
            "public function handle(): void;\n    public function flush(): void;",
            self::HANDLER_V1,
        );
        $root = $this->makeRepository(
            ['Api/Handler.php' => self::HANDLER_V1],
            ['Api/Handler.php' => $handlerV2],
        );

        [$exit, , $stderr] = $this->runCheck($root);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'BREAK added required member M|CrazyGoat\RabbitStream\Api\Handler::flush',
            $stderr
        );
    }

    public function testPassesWhenANewClassAndANewOptionalMethodAreAdded(): void
    {
        $extra = <<<'PHP'
            <?php

            namespace CrazyGoat\RabbitStream\Api;

            final class Extra
            {
            }
            PHP;
        $withOptionalMethod = str_replace(
            '    public function close(): void',
            "    public function reset(int \$limit = 5): void\n    {\n    }\n\n    public function close(): void",
            self::CLIENT_V1,
        );
        $this->assertStringContainsString('function reset(int $limit = 5)', $withOptionalMethod);
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => $withOptionalMethod, 'Api/Extra.php' => $extra],
        );

        [$exit, $stdout, $stderr] = $this->runCheck($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('0 break(s), 0 allowed.', $stdout);
    }

    public function testPassesWhenAnAllowListEntryNamesTheBreak(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => $this->withoutMethod(self::CLIENT_V1, 'close')],
            "M|CrazyGoat\\RabbitStream\\Api\\Client::close  #918 removed for 2.0.0\n",
        );

        [$exit, $stdout, $stderr] = $this->runCheck($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('ALLOWED removed M|CrazyGoat\RabbitStream\Api\Client::close', $stdout);
        $this->assertStringContainsString('1 allowed.', $stdout);
        $this->assertStringNotContainsString('BREAK', $stderr);
    }

    public function testWarnsOnAStaleAllowListEntryWithoutFailing(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => self::CLIENT_V1],
            "M|CrazyGoat\\RabbitStream\\Api\\Client::gone  #918 no longer removed\n",
        );

        [$exit, , $stderr] = $this->runCheck($root);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString(
            'WARNING stale allow-list entry M|CrazyGoat\RabbitStream\Api\Client::gone',
            $stderr
        );
    }

    public function testFailsWithAClearMessageWhenNoReleaseTagIsReachable(): void
    {
        $root = $this->makeRepository(
            ['Api/Client.php' => self::CLIENT_V1],
            ['Api/Client.php' => self::CLIENT_V1],
            null,
            false,
        );

        [$exit, , $stderr] = $this->runCheck($root);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('No release tag', $stderr);
        $this->assertStringContainsString('git fetch --tags --unshallow', $stderr);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runCheck(string $root): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-api-signature.php';
        $process = proc_open(
            [PHP_BINARY, $script, $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-api-signature.php');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Commits $tagFiles under src/ and tags the commit v1.0.0 (unless $tag is false).
     * Then replaces src/ in the working tree with $currentFiles, without committing.
     *
     * @param array<string, string> $tagFiles
     * @param array<string, string> $currentFiles
     */
    private function makeRepository(
        array $tagFiles,
        array $currentFiles,
        ?string $allowList = null,
        bool $tag = true
    ): string {
        $dir = sys_get_temp_dir() . '/rabbit-stream-api-signature-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        $this->writeTree($dir . '/src', $tagFiles);
        $this->git($dir, ['init', '-q']);
        $this->git($dir, ['add', '-A']);
        $this->git($dir, [
            '-c', 'user.name=test',
            '-c', 'user.email=test@example.invalid',
            'commit', '-q', '-m', 'initial',
        ]);
        if ($tag) {
            $this->git($dir, ['tag', 'v1.0.0']);
        }

        $this->removeDirectory($dir . '/src');
        $this->writeTree($dir . '/src', $currentFiles);
        if ($allowList !== null) {
            file_put_contents($dir . '/api-breaks.txt', $allowList);
        }

        $real = realpath($dir);
        if ($real === false) {
            $this->fail('Could not create a temporary repository root');
        }

        return $real;
    }

    /** @param list<string> $args */
    private function git(string $dir, array $args): void
    {
        $command = 'git -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->fail(sprintf("git failed (%s): %s", $command, implode("\n", $output)));
        }
    }

    /** @param array<string, string> $files */
    private function writeTree(string $dir, array $files): void
    {
        foreach ($files as $path => $contents) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }
    }

    private function withoutMethod(string $source, string $method): string
    {
        $pattern = '/\n    public function ' . preg_quote($method, '/') . '\(\): void\n    \{\n    \}\n/';
        $result = preg_replace($pattern, "\n", $source, 1);
        $this->assertIsString($result);

        return $result;
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
