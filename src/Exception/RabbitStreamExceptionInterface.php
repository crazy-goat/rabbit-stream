<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * Root contract of the library's exception hierarchy.
 *
 * Every throwable raised from `src/` implements this interface, so a single
 * `catch (RabbitStreamExceptionInterface $e)` around a publish or consume loop
 * sees them all — including the two that shadow a native name and therefore do
 * **not** extend {@see RabbitStreamException}:
 *
 * ```
 * RabbitStreamExceptionInterface (\Throwable)
 * ├── RabbitStreamException (\RuntimeException)
 * │   ├── ProtocolException
 * │   │   ├── AuthenticationException
 * │   │   └── UnexpectedResponseException
 * │   ├── ConnectionException
 * │   │   └── TimeoutException
 * │   ├── DeserializationException
 * │   ├── NoRouteForKeyException
 * │   └── UnsupportedPlatformException
 * ├── InvalidArgumentException (\InvalidArgumentException)
 * └── LengthException (\LengthException)
 * ```
 *
 * Prefer this interface when the only thing that matters is "a RabbitStream
 * error happened"; catch a concrete class (or {@see RabbitStreamException})
 * when the distinction drives the recovery. Beware the two native-shadowing
 * exceptions: a `catch (RabbitStreamException $e)` does **not** catch
 * {@see InvalidArgumentException} or {@see LengthException}. See
 * `docs/en/guide/error-handling.md` for the full catch guidance.
 */
interface RabbitStreamExceptionInterface extends \Throwable
{
}
