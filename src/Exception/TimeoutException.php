<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * An operation did not complete within its deadline.
 *
 * The subclass of {@see ConnectionException} that is safe to retry, because
 * nothing — or at most nothing of a partial frame — is known to be lost:
 * {@see \CrazyGoat\RabbitStream\StreamConnection::readMessage()} throws it when
 * no response frame arrives in time, `sendFrame()` throws it when the socket
 * never becomes writable, and the high-level
 * {@see \CrazyGoat\RabbitStream\Client\Producer::waitForConfirms()} throws it
 * when confirmations do not arrive before the deadline.
 *
 * Catch it before {@see ConnectionException}, which it extends.
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\TimeoutException;
 *
 * try {
 *     $producer->waitForConfirms(timeout: 5.0);
 * } catch (TimeoutException $e) {
 *     // Nothing confirmed in time; the connection is still usable.
 * }
 * ```
 */
class TimeoutException extends ConnectionException
{
}
