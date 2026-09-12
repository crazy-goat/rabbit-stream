<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * The server answered a request with a response class the client did not expect.
 *
 * Built through {@see self::create()}, which records both class names, and
 * thrown by the high-level {@see \CrazyGoat\RabbitStream\Client\Connection},
 * {@see \CrazyGoat\RabbitStream\Client\Consumer} and
 * {@see \CrazyGoat\RabbitStream\Client\Producer} after `readMessage()` returns
 * an object that does not match the response expected for the request. A
 * healthy conversation never produces it: it means the request/response stream
 * is desynchronised (a protocol-version mismatch, or a response consumed by the
 * wrong call), so treat it as fatal for the connection.
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
 *
 * try {
 *     $connection->deleteStream('my-stream');
 * } catch (UnexpectedResponseException $e) {
 *     error_log(sprintf('Expected %s, got %s', $e->getExpectedClass(), $e->getActualClass()));
 *     $connection->close();
 * }
 * ```
 */
class UnexpectedResponseException extends ProtocolException
{
    private string $expectedClass;
    private string $actualClass;

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function create(string $expected, object $actual): self
    {
        $exception = new self(
            sprintf('Expected %s, got %s', $expected, $actual::class)
        );
        $exception->expectedClass = $expected;
        $exception->actualClass = $actual::class;

        return $exception;
    }

    public function getExpectedClass(): string
    {
        return $this->expectedClass;
    }

    public function getActualClass(): string
    {
        return $this->actualClass;
    }
}
