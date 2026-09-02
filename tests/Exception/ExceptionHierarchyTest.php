<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Exception;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\LengthException;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards the #242 exception hierarchy against the regression #394/#465/#466 all
 * described: a throwable raised from src/ that a
 * catch (RabbitStreamExceptionInterface) does not see.
 */
class ExceptionHierarchyTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../src';

    public function testEveryExceptionClassImplementsTheLibraryInterface(): void
    {
        $classes = [];
        foreach (glob(self::SRC_DIR . '/Exception/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'RabbitStreamExceptionInterface') {
                continue;
            }
            $classes[] = 'CrazyGoat\\RabbitStream\\Exception\\' . $name;
        }

        $this->assertNotEmpty($classes, 'No exception classes found in src/Exception');
        foreach ($classes as $class) {
            $this->assertTrue(
                is_a($class, RabbitStreamExceptionInterface::class, true),
                $class . ' must implement RabbitStreamExceptionInterface'
            );
        }
    }

    /**
     * A native throwable constructed with `throw new \Something(...)` cannot
     * implement RabbitStreamExceptionInterface, so any such site in src/ is a
     * hole in the hierarchy. Library exceptions that shadow a native name
     * (InvalidArgumentException, LengthException) are imported and therefore
     * written without the leading backslash — which is exactly what makes this
     * a reliable grep.
     */
    public function testNoNativeThrowableIsThrownAnywhereInSrc(): void
    {
        $offenders = [];
        foreach ($this->phpFilesInSrc() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $i => $line) {
                if (preg_match('/throw new \\\\[A-Z]/', $line) === 1) {
                    $offenders[] = sprintf(
                        '%s:%d: %s',
                        substr($file, strlen(dirname(self::SRC_DIR)) + 1),
                        $i + 1,
                        trim($line)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Throw a CrazyGoat\\RabbitStream\\Exception\\* class instead:\n" . implode("\n", $offenders)
        );
    }

    /**
     * @return array<string, array{class-string<\Throwable>, class-string<\Throwable>}>
     */
    public static function nativeShadowingExceptions(): array
    {
        return [
            'InvalidArgumentException' => [InvalidArgumentException::class, \InvalidArgumentException::class],
            'LengthException' => [LengthException::class, \LengthException::class],
        ];
    }

    /**
     * Two library exceptions deliberately shadow a native name and extend it, so that
     * joining the hierarchy (#465, #394) is not a BC break for callers already catching
     * the native type. Locking that in: dropping the `extends` would compile fine.
     *
     * @param class-string<\Throwable> $library
     * @param class-string<\Throwable> $native
     * @dataProvider nativeShadowingExceptions
     */
    public function testShadowingExceptionsStillExtendTheirNativeCounterpart(
        string $library,
        string $native
    ): void {
        $this->assertTrue(is_a($library, $native, true), $library . ' must extend ' . $native);
        $this->assertTrue(is_a($library, RabbitStreamExceptionInterface::class, true));
    }

    /** @return list<string> */
    private function phpFilesInSrc(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->getExtension() === 'php') {
                $files[] = $info->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
