<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * A value is too long to be represented on the wire.
 *
 * Today that is a single case: a payload larger than the AMQP 1.0 `vbin32`
 * limit of 4294967295 bytes, whose 32-bit length prefix would wrap modulo 2^32
 * and corrupt framing. {@see \CrazyGoat\RabbitStream\Client\AmqpMessageEncoder}
 * throws it before that can happen.
 *
 * Extends the native \LengthException — like InvalidArgumentException does for
 * its native counterpart — so callers that already catch \LengthException (or
 * \LogicException) keep working. Like {@see InvalidArgumentException} it does
 * **not** extend {@see RabbitStreamException}; catch it directly or catch
 * {@see RabbitStreamExceptionInterface} (#394).
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\LengthException;
 *
 * try {
 *     $producer->send($hugePayload);
 * } catch (LengthException $e) {
 *     // "AMQP 1.0 Data section payload exceeds the 4294967295-byte vbin32 limit"
 * }
 * ```
 */
class LengthException extends \LengthException implements RabbitStreamExceptionInterface
{
}
