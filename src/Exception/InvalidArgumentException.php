<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * A caller-supplied value is invalid.
 *
 * Thrown for programming errors before anything reaches the wire: an integer
 * outside the protocol range or a non-UTF-8 string in
 * {@see \CrazyGoat\RabbitStream\Buffer\WriteBuffer}, an unknown offset type in
 * `OffsetSpec`, an unknown partition in
 * {@see \CrazyGoat\RabbitStream\Client\SuperStreamConsumer}, an empty partition
 * list in the hash routing strategy, a negative or out-of-range buffer/credit/timeout
 * option in {@see \CrazyGoat\RabbitStream\Client\Consumer},
 * {@see \CrazyGoat\RabbitStream\Client\Producer} or
 * {@see \CrazyGoat\RabbitStream\StreamConnection}, and an uncorrelated request
 * passed to `StreamConnection::request()`.
 *
 * **Inheritance quirk — this class does NOT extend
 * {@see RabbitStreamException}.** It extends the native
 * `\InvalidArgumentException` and only implements
 * {@see RabbitStreamExceptionInterface}. As a result a
 * `catch (RabbitStreamException $e)` will **not** catch it, and callers who
 * only catch the library base class can miss a malformed argument entirely.
 * Catch this class directly, or catch {@see RabbitStreamExceptionInterface},
 * which both this class and {@see RabbitStreamException} implement. The native
 * parent is kept on purpose so code that already catches
 * `\InvalidArgumentException` (or `\LogicException`) keeps working (#465).
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
 * use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
 *
 * try {
 *     $producer = $connection->createProducer('my-stream'); // unnamed producer
 *     $producer->querySequence();                            // requires a name
 * } catch (InvalidArgumentException $e) {
 *     // Caught. A catch (RabbitStreamException) would NOT have caught it.
 * } catch (RabbitStreamExceptionInterface $e) {
 *     // Wider net: catches this and every RabbitStreamException too.
 * }
 * ```
 */
class InvalidArgumentException extends \InvalidArgumentException implements RabbitStreamExceptionInterface
{
}
