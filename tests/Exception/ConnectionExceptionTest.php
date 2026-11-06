<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Exception;

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

class ConnectionExceptionTest extends TestCase
{
    public function testPlainConnectionExceptionHasNoServerCloseValues(): void
    {
        $exception = new ConnectionException('Connection closed');

        self::assertNull($exception->getServerCloseCode());
        self::assertNull($exception->getServerCloseReason());
    }

    public function testServerClosedKeepsCodeAndReasonAndNamesThemInTheMessage(): void
    {
        $exception = ConnectionException::serverClosed(320, 'Closed via management plugin');

        self::assertSame(
            'Connection closed by server: code=320, reason=Closed via management plugin',
            $exception->getMessage()
        );
        self::assertSame(320, $exception->getServerCloseCode());
        self::assertSame('Closed via management plugin', $exception->getServerCloseReason());
    }

    public function testServerClosedKeepsAnEmptyReason(): void
    {
        $exception = ConnectionException::serverClosed(1, '');

        self::assertSame('Connection closed by server: code=1, reason=', $exception->getMessage());
        self::assertSame('', $exception->getServerCloseReason());
    }
}
