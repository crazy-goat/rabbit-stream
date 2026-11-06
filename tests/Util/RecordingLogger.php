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
        return $this->messagesAtLevel('debug');
    }

    /**
     * Return the PSR-3 context array of every debug-level record, in order.
     *
     * @return list<array<mixed>>
     */
    public function debugContexts(): array
    {
        return $this->contextsAtLevel('debug');
    }

    /**
     * Return all warning-level message strings in order.
     *
     * @return array<string>
     */
    public function warningMessages(): array
    {
        return $this->messagesAtLevel('warning');
    }

    /**
     * Return the PSR-3 context array of every warning-level record, in order.
     *
     * @return list<array<mixed>>
     */
    public function warningContexts(): array
    {
        return $this->contextsAtLevel('warning');
    }

    /**
     * @return list<array<mixed>>
     */
    private function contextsAtLevel(string $level): array
    {
        return array_values(array_map(
            static fn (array $r): array => $r['context'],
            array_filter(
                $this->records,
                static fn (array $r): bool => $r['level'] === $level
            )
        ));
    }

    /**
     * Return all error-level message strings in order.
     *
     * @return array<string>
     */
    public function errorMessages(): array
    {
        return $this->messagesAtLevel('error');
    }

    /**
     * Return the PSR-3 context array of every error-level record, in order.
     *
     * @return list<array<mixed>>
     */
    public function errorContexts(): array
    {
        return $this->contextsAtLevel('error');
    }

    /**
     * @return array<string>
     */
    private function messagesAtLevel(string $level): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['message'],
            array_filter(
                $this->records,
                static fn (array $r): bool => $r['level'] === $level
            )
        ));
    }
}
