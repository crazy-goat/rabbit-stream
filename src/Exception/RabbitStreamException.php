<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * Base class for every exception the library throws itself.
 *
 * It is never thrown directly — throw a more specific subclass
 * ({@see ProtocolException}, {@see ConnectionException}, …). Its purpose is to
 * let `catch (RabbitStreamException $e)` wrap every runtime failure in one
 * clause. For the wider net that also covers the two native-shadowing
 * exceptions use {@see RabbitStreamExceptionInterface}.
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\RabbitStreamException;
 *
 * try {
 *     $producer->send($message);
 *     $producer->waitForConfirms(timeout: 5.0);
 * } catch (RabbitStreamException $e) {
 *     error_log('RabbitStream error: ' . $e->getMessage());
 * }
 * ```
 *
 * Extends \RuntimeException because these are runtime failures (a dead socket,
 * a broker error code), not programming errors. The two programming-error
 * exceptions, {@see InvalidArgumentException} and {@see LengthException},
 * deliberately do not extend this class and are not caught here.
 */
class RabbitStreamException extends \RuntimeException implements RabbitStreamExceptionInterface
{
}
