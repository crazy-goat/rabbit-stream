<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CheckDocsSymbolsTest extends TestCase
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

    public function testReportsClassReferenceOutsideCodeFence(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "See `\\CrazyGoat\\RabbitStream\\Missing\\Ghost`.\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:1: unknown class CrazyGoat\\RabbitStream\\Missing\\Ghost',
            $stderr
        );
    }

    public function testFailsOnUnknownClassWithPageLineAndSymbol(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "# Example\n\nSee `\\CrazyGoat\\RabbitStream\\Missing\\Ghost`.\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:3: unknown class CrazyGoat\\RabbitStream\\Missing\\Ghost',
            $stderr
        );
    }

    public function testFailsOnMissingClassInExistingNamespace(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nnew \\CrazyGoat\\RabbitStream\\Request\\Ghost();\n```\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:2: unknown class CrazyGoat\\RabbitStream\\Request\\Ghost',
            $stderr
        );
    }

    public function testFailsOnUnknownImportedClass(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nuse CrazyGoat\\RabbitStream\\Missing\\Ghost;\n```\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:2: unknown class CrazyGoat\\RabbitStream\\Missing\\Ghost',
            $stderr
        );
    }

    public function testAllowsImportFromTestSubnamespace(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertIsArray($composer['autoload-dev']['psr-4'] ?? null);
        $testNamespace = rtrim((string) array_key_first($composer['autoload-dev']['psr-4']), '\\');
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nuse {$testNamespace}\\Request\\SomeTest;\n```\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($docs);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('All class references and named constructor arguments', $stdout);
    }

    public function testFailsOnUnimportedBareConstructorClass(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nnew NopeClass();\n```\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:2: class NopeClass is not imported or fully qualified',
            $stderr
        );
        $this->assertStringContainsString(
            'Add a use statement or the fully qualified name, or add the bare name to '
            . '$allowedUnqualifiedClasses in bin/check-docs-symbols.php',
            $stderr
        );
    }

    public function testAllowsBareConstructorClassFromAllowList(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nnew producer(1);\n```\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(0, $exit, $stderr);
    }

    public function testFailsOnUnknownNamedConstructorArgument(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => implode("\n", [
                '```php',
                'use CrazyGoat\\RabbitStream\\Request\\DeleteSuperStreamRequestV1;',
                '',
                "new DeleteSuperStreamRequestV1(superStream: 'events');",
                '```',
                '',
            ]),
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:4: unknown named argument superStream for '
            . \CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1::class,
            $stderr
        );
    }

    public function testAllowsExistingNamedConstructorArgumentAndIgnoreMarker(): void
    {
        $docs = $this->makeDocs([
            'guide/valid.md' => implode("\n", [
                '```php',
                'use CrazyGoat\\RabbitStream\\Request\\DeleteSuperStreamRequestV1;',
                "new DeleteSuperStreamRequestV1(name: 'events');",
                '```',
                '',
            ]),
            'guide/illustrative.md' => implode("\n", [
                '<!-- docs-lint: ignore -->',
                '```php',
                'use CrazyGoat\\RabbitStream\\Missing\\Ghost;',
                'new Ghost(unknown: true);',
                '```',
                '',
            ]),
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($docs);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('All class references and named constructor arguments', $stdout);
    }

    public function testUnknownNamespaceInUseIsNotTreatedAsClass(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => implode("\n", [
                '```php',
                'use CrazyGoat\\RabbitStream\\Request;',
                '```',
                '',
            ]),
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(0, $exit, $stderr);
    }

    public function testChecksOnlyPhpFences(): void
    {
        $docs = $this->makeDocs([
            'guide/example.md' => "```text\nCrazyGoat\\RabbitStream\\Missing\\Ghost\n```\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs);

        $this->assertSame(0, $exit, $stderr);
    }

    public function testAllowsImportFromCustomTestPrefixInComposerJson(): void
    {
        $composer = $this->makeComposer('{"autoload-dev": {"psr-4": {"Foo\\\\Tests\\\\": "tests/"}}}');
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nuse Foo\\Tests\\Request\\SomeTest;\n```\n",
        ]);

        [$exit, $stdout, $stderr] = $this->runGate($docs, $composer);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('All class references and named constructor arguments', $stdout);
    }

    public function testDefaultTestNamespaceIsNotAllowedWhenComposerJsonNamesAnotherPrefix(): void
    {
        $composer = $this->makeComposer('{"autoload-dev": {"psr-4": {"Foo\\\\Tests\\\\": "tests/"}}}');
        $docs = $this->makeDocs([
            'guide/example.md' => "```php\nuse CrazyGoat\\RabbitStream\\Tests\\Request\\SomeTest;\n```\n",
        ]);

        [$exit, , $stderr] = $this->runGate($docs, $composer);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'guide/example.md:2: unknown class CrazyGoat\\RabbitStream\\Tests\\Request\\SomeTest',
            $stderr
        );
    }

    public function testFailsWhenComposerJsonIsMissing(): void
    {
        $missing = $this->makeDocs(['marker.txt' => '']) . '/composer.json';
        $docs = $this->makeDocs(['guide/example.md' => "# Example\n"]);

        [$exit, , $stderr] = $this->runGate($docs, $missing);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Cannot read composer file: ' . $missing, $stderr);
        $this->assertStringNotContainsString('Warning', $stderr);
    }

    public function testFailsWhenComposerJsonIsNotValidJson(): void
    {
        $composer = $this->makeComposer('{"autoload-dev": ');
        $docs = $this->makeDocs(['guide/example.md' => "# Example\n"]);

        [$exit, , $stderr] = $this->runGate($docs, $composer);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('composer file is not valid JSON: ' . $composer, $stderr);
        $this->assertStringNotContainsString('no autoload-dev PSR-4 prefix', $stderr);
    }

    public function testFailsWhenComposerJsonIsNotAnObject(): void
    {
        $composer = $this->makeComposer('"just a string"');
        $docs = $this->makeDocs(['guide/example.md' => "# Example\n"]);

        [$exit, , $stderr] = $this->runGate($docs, $composer);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('composer file is not a JSON object: ' . $composer, $stderr);
    }

    public function testFailsWhenComposerJsonHasNoAutoloadDevPrefix(): void
    {
        $composer = $this->makeComposer('{"autoload": {"psr-4": {"Foo\\\\": "src/"}}}');
        $docs = $this->makeDocs(['guide/example.md' => "# Example\n"]);

        [$exit, , $stderr] = $this->runGate($docs, $composer);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString(
            'composer file has no autoload-dev PSR-4 prefix for the test namespace: ' . $composer,
            $stderr
        );
    }

    public function testFailsWhenComposerJsonPrefixIsEmpty(): void
    {
        $composer = $this->makeComposer('{"autoload-dev": {"psr-4": {"": "tests/"}}}');
        $docs = $this->makeDocs(['guide/example.md' => "# Example\n"]);

        [$exit, , $stderr] = $this->runGate($docs, $composer);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('has no autoload-dev PSR-4 prefix', $stderr);
    }

    /** @return array{int, string, string} */
    private function runGate(string $docs, ?string $composer = null): array
    {
        $script = dirname(__DIR__, 2) . '/bin/check-docs-symbols.php';
        $command = [PHP_BINARY, $script, $docs];
        if ($composer !== null) {
            $command[] = $composer;
        }
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Could not start bin/check-docs-symbols.php');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function makeComposer(string $contents): string
    {
        return $this->makeDocs(['composer.json' => $contents]) . '/composer.json';
    }

    /**
     * @param array<string, string> $files
     */
    private function makeDocs(array $files): string
    {
        $dir = sys_get_temp_dir() . '/rabbit-stream-docs-symbols-' . bin2hex(random_bytes(8));
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
