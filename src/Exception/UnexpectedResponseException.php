<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

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
