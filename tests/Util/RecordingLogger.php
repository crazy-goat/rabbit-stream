<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal in-memory PSR-3 logger that records all log calls for test assertions.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelStr = is_string($level) ? $level : '';
        $messageStr = is_string($message) ? $message : $message->__toString();
        $this->records[] = [
            'level' => $levelStr,
            'message' => $messageStr,
            'context' => $context,
        ];
    }

    /**
     * Return all debug-level message strings in order.
     *
     * @return array<string>
     */
    public function debugMessages(): array
    {
        return array_map(
            static fn (array $r): string => $r['message'],
            array_filter(
                $this->records,
                static fn (array $r): bool => $r['level'] === 'debug'
            )
        );
    }
}
