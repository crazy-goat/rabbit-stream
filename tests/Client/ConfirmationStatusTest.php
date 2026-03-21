<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use PHPUnit\Framework\TestCase;

class ConfirmationStatusTest extends TestCase
{
    public function testConfirmedStatus(): void
    {
        $status = new ConfirmationStatus(true, publishingId: 42);
        $this->assertTrue($status->isConfirmed());
        $this->assertNull($status->getErrorCode());
        $this->assertSame(42, $status->getPublishingId());
    }

    public function testErrorStatus(): void
    {
        $status = new ConfirmationStatus(false, errorCode: 0x02, publishingId: 7);
        $this->assertFalse($status->isConfirmed());
        $this->assertSame(0x02, $status->getErrorCode());
        $this->assertSame(7, $status->getPublishingId());
    }

    public function testConfirmedStatusWithNullPublishingId(): void
    {
        $status = new ConfirmationStatus(true);
        $this->assertTrue($status->isConfirmed());
        $this->assertNull($status->getErrorCode());
        $this->assertNull($status->getPublishingId());
    }

    public function testErrorStatusWithNullPublishingId(): void
    {
        $status = new ConfirmationStatus(false, errorCode: 0x01);
        $this->assertFalse($status->isConfirmed());
        $this->assertSame(0x01, $status->getErrorCode());
        $this->assertNull($status->getPublishingId());
    }

    public function testZeroPublishingId(): void
    {
        $status = new ConfirmationStatus(true, publishingId: 0);
        $this->assertTrue($status->isConfirmed());
        $this->assertSame(0, $status->getPublishingId());
    }

    public function testZeroErrorCode(): void
    {
        $status = new ConfirmationStatus(false, errorCode: 0, publishingId: 1);
        $this->assertFalse($status->isConfirmed());
        $this->assertSame(0, $status->getErrorCode());
    }
}
