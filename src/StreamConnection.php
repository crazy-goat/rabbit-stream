<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\ConsumerUpdateReplyV1;
use CrazyGoat\RabbitStream\Request\HeartbeatRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class StreamConnection
{
    private bool $connected = false;
    private ?\Socket $socket = null;
    private int $correlationId = 0;
    private bool $running = false;
    private readonly bool $debugLogging;

    /** @var array<int, array{onConfirm: callable, onError: callable}> */
    private array $publisherCallbacks = [];
    /** @var array<int, callable> */
    private array $subscriberCallbacks = [];
    private ?\Closure $metadataUpdateCallback = null;
    private ?\Closure $heartbeatCallback = null;
    private ?\Closure $consumerUpdateCallback = null;

    private const SERVER_PUSH_KEYS = [
        0x0003, // PublishConfirm
        0x0004, // PublishError
        0x0008, // Deliver
        0x0010, // MetadataUpdate
        0x0016, // Close (server-initiated)
        0x0017, // Heartbeat
        0x001a, // ConsumerUpdate
    ];

    public const DEFAULT_MAX_FRAME_SIZE = 8 * 1024 * 1024; // 8MB safety limit

    private int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;

    /**
     * @param string                $host     RabbitMQ stream server hostname
     * @param int                   $port     RabbitMQ stream server port
     * @param LoggerInterface       $logger   PSR-3 logger (defaults to NullLogger)
     * @param BinarySerializerInterface $serializer Serializer for request/response frames
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 5552,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly BinarySerializerInterface $serializer = new PhpBinarySerializer(),
    ) {
        // Resolve once at construction: avoids paying bin2hex() cost on every
        // frame when the logger won't emit debug records (NullLogger default).
        $this->debugLogging = !$logger instanceof NullLogger;
    }

    /**
     * Open the TCP socket connection to the RabbitMQ stream server.
     *
     * @throws ConnectionException If the socket cannot be created or connected
     */
    public function connect(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new ConnectionException("Cannot create socket: " . socket_strerror(socket_last_error()));
        }

        $result = socket_connect($socket, $this->host, $this->port);
        if (!$result) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new ConnectionException(
                "Cannot connect to {$this->host}:{$this->port}: " . $error
            );
        }

        $this->connected = true;
        $this->socket = $socket;
    }

    /**
     * Close the TCP socket connection.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function close(): void
    {
        if ($this->connected && $this->socket instanceof \Socket) {
            try {
                socket_close($this->socket);
            } catch (\Throwable) {
                // Socket may already be closed, ignore
            }
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Close the connection on object destruction.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Check whether the underlying TCP socket is currently connected and usable.
     *
     * @return bool True if the socket is valid and has no error state
     */
    public function isConnected(): bool
    {
        if (!$this->connected) {
            return false;
        }

        if (!$this->socket instanceof \Socket) {
            return false;
        }

        // Check if socket is still valid by attempting to get its error status
        // A closed socket will fail this operation
        try {
            $error = @socket_last_error($this->socket);
            if ($error !== 0) {
                // Socket has an error, mark as disconnected
                $this->connected = false;
                return false;
            }
        } catch (\Error) {
            // Socket is invalid/closed
            $this->connected = false;
            return false;
        }

        return true;
    }

    /**
     * Set the maximum allowed frame size in bytes.
     * Frames larger than this will cause the connection to be closed.
     *
     * @param int $maxFrameSize Maximum frame size in bytes (0 = no limit)
     * @throws InvalidArgumentException If the value is negative
     */
    public function setMaxFrameSize(int $maxFrameSize): void
    {
        if ($maxFrameSize < 0) {
            throw new InvalidArgumentException(
                "Max frame size must be >= 0 (0 = no limit), got {$maxFrameSize}"
            );
        }
        $this->maxFrameSize = $maxFrameSize;
    }

    /**
     * Get the current maximum allowed frame size in bytes.
     *
     * @return int Maximum frame size (0 = no limit)
     */
    public function getMaxFrameSize(): int
    {
        return $this->maxFrameSize;
    }

    /**
     * Register callbacks for publish confirm/error notifications.
     *
     * @param int      $publisherId Publisher ID as declared with the server
     * @param callable $onConfirm   Called with (array $publishingIds) when messages are confirmed
     * @param callable $onError     Called with (array $errors) when messages fail
     */
    public function registerPublisher(int $publisherId, callable $onConfirm, callable $onError): void
    {
        $this->publisherCallbacks[$publisherId] = [
            'onConfirm' => $onConfirm,
            'onError' => $onError,
        ];
    }

    /**
     * Register a callback for message delivery notifications.
     *
     * @param int      $subscriptionId Subscription ID as declared with the server
     * @param callable $onDeliver      Called with (DeliverResponseV1 $deliver) for each delivered chunk
     */
    public function registerSubscriber(int $subscriptionId, callable $onDeliver): void
    {
        $this->subscriberCallbacks[$subscriptionId] = $onDeliver;
    }

    /**
     * Remove a previously registered subscriber callback.
     *
     * @param int $subscriptionId Subscription ID to unregister
     */
    public function unregisterSubscriber(int $subscriptionId): void
    {
        unset($this->subscriberCallbacks[$subscriptionId]);
    }

    /**
     * Remove a previously registered publisher callback.
     *
     * @param int $publisherId Publisher ID to unregister
     */
    public function unregisterPublisher(int $publisherId): void
    {
        unset($this->publisherCallbacks[$publisherId]);
    }

    /**
     * Register a callback for metadata update notifications from the server.
     *
     * @param callable $callback Called with (MetadataUpdateResponseV1 $update) on topology changes
     */
    public function onMetadataUpdate(callable $callback): void
    {
        $this->metadataUpdateCallback = \Closure::fromCallable($callback);
    }

    /**
     * Register a callback for heartbeat notifications.
     * Pass null to disable the callback.
     *
     * @param callable|null $callback Called after each heartbeat echo (or null to clear)
     */
    public function onHeartbeat(?callable $callback = null): void
    {
        $this->heartbeatCallback = $callback !== null ? \Closure::fromCallable($callback) : null;
    }

    /**
     * Register a callback for consumer update requests from the server.
     *
     * @param callable $callback Called with (ConsumerUpdateResponseV1 $update); must return
     *                           [int $offsetType, int $offset] for the reply
     */
    public function onConsumerUpdate(callable $callback): void
    {
        $this->consumerUpdateCallback = \Closure::fromCallable($callback);
    }

    /**
     * Signal the readLoop to stop gracefully at the next iteration.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Serialize and send a protocol request object to the server.
     * Automatically assigns a correlation ID if the request supports it.
     *
     * @param object     $request Request object implementing ToStreamBufferInterface
     * @param float|null $timeout Optional write timeout in seconds
     * @throws ConnectionException      If the socket is not connected
     * @throws InvalidArgumentException If the request does not implement ToStreamBufferInterface
     * @throws TimeoutException         If the write times out
     */
    public function sendMessage(object $request, ?float $timeout = null): void
    {
        if ($request instanceof CorrelationInterface) {
            $this->correlationId++;
            $request->withCorrelationId($this->correlationId);
        }

        $content = $this->serializer->serialize($request);

        $this->sendFrame($this->wrapFrame($content), $timeout);
    }

    /**
     * Write a raw binary frame to the socket.
     *
     * @param string     $frame  The complete frame payload (including length prefix)
     * @param float|null $timeout Optional write timeout in seconds
     * @return int Number of bytes written
     * @throws ConnectionException If the socket is not connected or a write error occurs
     * @throws TimeoutException    If the socket is not ready for writing within the timeout
     */
    public function sendFrame(string $frame, ?float $timeout = null): int
    {
        $this->debugFrame('Socket -> ', $frame, keyOffset: 4);

        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot write: socket is not connected");
        }

        // If timeout is specified, wait for socket to be ready for writing
        if ($timeout !== null && $timeout > 0) {
            $deadline = microtime(true) + $timeout;

            $read = null;
            $write = [$this->socket];
            $except = null;

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException("Write timeout: socket not ready for writing");
            }

            $timeoutSec = (int) $remaining;
            $timeoutUsec = (int) (($remaining - $timeoutSec) * 1_000_000);

            $ready = socket_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($ready === false) {
                throw new ConnectionException(
                    "socket_select failed: " . socket_strerror(socket_last_error($this->socket))
                );
            }

            if ($ready === 0) {
                throw new TimeoutException("Write timeout: socket not ready for writing");
            }
        }

        try {
            $written = socket_write($this->socket, $frame, strlen($frame));
        } catch (\Error $e) {
            $this->connected = false;
            throw new ConnectionException("Failed to write to socket: " . $e->getMessage(), $e->getCode(), $e);
        }

        if ($written === false) {
            throw new ConnectionException(
                "Failed to write to socket: " . socket_strerror(socket_last_error($this->socket))
            );
        }

        return $written;
    }

    /**
     * Read and deserialize the next non-server-push response frame.
     * Server-push frames (heartbeat, publish confirm, deliver, etc.) are dispatched
     * transparently to registered callbacks before returning.
     *
     * @param float $timeout Seconds to wait before throwing TimeoutException.
     *                       0.0 means non-blocking (throws TimeoutException immediately if no data).
     * @return object Deserialized response object
     * @throws ConnectionException      If the socket is closed or a read error occurs
     * @throws DeserializationException If the response frame cannot be deserialized
     * @throws ProtocolException        If the response uses an unexpected protocol version or command
     * @throws TimeoutException         If no response arrives within $timeout seconds
     */
    public function readMessage(float $timeout = 30.0): object
    {
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        while (true) {
            if (!$this->connected) {
                throw new ConnectionException("Connection closed");
            }

            $remainingTimeout = $timeout;
            if ($deadline !== null) {
                $remainingTimeout = $deadline - microtime(true);
                if ($remainingTimeout <= 0) {
                    throw new TimeoutException("Read timeout");
                }
            }

            $frame = $this->readFrame($remainingTimeout);
            if (!$frame instanceof \CrazyGoat\RabbitStream\Buffer\ReadBuffer) {
                throw new TimeoutException("Read timeout");
            }

            $key = $frame->peekUint16();

            if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
                $this->dispatchServerPush($frame);

                // Connection may have been closed by server-initiated close
                if (!$this->connected) {
                    throw new ConnectionException("Connection closed by server");
                }

                continue;
            }

            return $this->serializer->deserialize($frame->getRemainingBytes());
        }
    }

    /**
     * Enter a read loop that dispatches server-push frames to registered callbacks.
     * The loop continues until one of:
     *   - `stop()` is called
     *   - The connection is closed
     *   - `$maxFrames` frames have been dispatched
     *   - `$timeout` seconds have elapsed
     *
     * If both `$maxFrames` and `$timeout` are null, the loop runs indefinitely
     * (until `stop()` or disconnect).
     *
     * @param int|null   $maxFrames Maximum number of frames to dispatch (null = unlimited)
     * @param float|null $timeout   Maximum wall-clock time in seconds (null = unlimited)
     * @throws ConnectionException If the socket is not connected
     */
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): void
    {
        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot read: socket is not connected");
        }

        $this->running = true;
        $dispatched = 0;
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while ($this->running && $this->connected) {
            // Check if timeout has expired
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;

            // Calculate remaining timeout for socket_select.
            // Cap $remaining BEFORE deriving both halves: select(2) rejects
            // tv_usec >= 1_000_000 with EINVAL (e.g. 2.5s would produce
            // sec = 1, usec = 1_500_000 without the cap).
            $selectTimeoutSec = 1;
            $selectTimeoutUsec = 0;
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $capped = min($remaining, 1);
                $selectTimeoutSec = (int) $capped;
                $selectTimeoutUsec = (int) (($capped - $selectTimeoutSec) * 1_000_000);
            }

            $ready = socket_select($read, $write, $except, $selectTimeoutSec, $selectTimeoutUsec);

            if ($ready === false) {
                throw new ConnectionException(
                    'socket_select failed: ' . socket_strerror(socket_last_error($this->socket))
                );
            }

            if ($ready === 0) {
                continue;
            }

            $frame = $this->readFrame(timeout: 0.0);
            if (!$frame instanceof \CrazyGoat\RabbitStream\Buffer\ReadBuffer) {
                continue;
            }

            $key = $frame->peekUint16();

            if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
                $this->dispatchServerPush($frame);
                $dispatched++;

                // Connection may have been closed by server-initiated close
                if (!$this->connected) {
                    break;
                }
            } else {
                $this->logger->warning(
                    'readLoop() received unexpected non-server-push frame, discarding',
                    ['key' => sprintf('0x%04x', $key)]
                );
            }

            if ($maxFrames !== null && $dispatched >= $maxFrames) {
                break;
            }
        }

        $this->running = false;
    }

    private function dispatchServerPush(ReadBuffer $frame): void
    {
        $key = $frame->peekUint16();

        match ($key) {
            KeyEnum::HEARTBEAT->value => $this->handleHeartbeat($frame),
            KeyEnum::PUBLISH_CONFIRM->value => $this->handlePublishConfirm($frame),
            KeyEnum::PUBLISH_ERROR->value => $this->handlePublishError($frame),
            KeyEnum::DELIVER->value => $this->handleDeliver($frame),
            KeyEnum::CLOSE->value => $this->handleServerClose($frame),
            KeyEnum::METADATA_UPDATE->value => $this->handleMetadataUpdate($frame),
            KeyEnum::CONSUMER_UPDATE->value => $this->handleConsumerUpdate($frame),
            default => null,
        };
    }

    private function handleHeartbeat(ReadBuffer $frame): void
    {
        HeartbeatRequestV1::fromStreamBuffer($frame);
        $heartbeat = new HeartbeatRequestV1();
        $content = $this->serializer->serialize($heartbeat);
        $this->sendFrame($this->wrapFrame($content));
        if ($this->heartbeatCallback instanceof \Closure) {
            ($this->heartbeatCallback)();
        }
    }

    private function handlePublishConfirm(ReadBuffer $frame): void
    {
        $confirm = PublishConfirmResponseV1::fromStreamBuffer($frame);
        if (!$confirm instanceof PublishConfirmResponseV1) {
            throw new DeserializationException('Failed to deserialize PublishConfirm frame');
        }
        $publisherId = $confirm->getPublisherId();
        if (isset($this->publisherCallbacks[$publisherId])) {
            ($this->publisherCallbacks[$publisherId]['onConfirm'])($confirm->getPublishingIds());
        }
    }

    private function handlePublishError(ReadBuffer $frame): void
    {
        $error = PublishErrorResponseV1::fromStreamBuffer($frame);
        if (!$error instanceof PublishErrorResponseV1) {
            throw new DeserializationException('Failed to deserialize PublishError frame');
        }
        $publisherId = $error->getPublisherId();
        if (isset($this->publisherCallbacks[$publisherId])) {
            ($this->publisherCallbacks[$publisherId]['onError'])($error->getErrors());
        }
    }

    private function handleDeliver(ReadBuffer $frame): void
    {
        $deliver = DeliverResponseV1::fromStreamBuffer($frame);
        if (!$deliver instanceof DeliverResponseV1) {
            throw new DeserializationException('Failed to deserialize Deliver frame');
        }
        $subscriptionId = $deliver->getSubscriptionId();
        if (isset($this->subscriberCallbacks[$subscriptionId])) {
            ($this->subscriberCallbacks[$subscriptionId])($deliver);
        }
    }

    private function handleServerClose(ReadBuffer $frame): void
    {
        $frame->getUint16(); // key
        $frame->getUint16(); // version
        $correlationId = $frame->getUint32();
        $closingCode = $frame->getUint16();
        $closingReason = $frame->getString();
        $this->logger->debug(sprintf(
            'Server-initiated close: code=%d, reason=%s',
            $closingCode,
            $closingReason ?? ''
        ));

        $response = (new WriteBuffer())
            ->addUInt16(KeyEnum::CLOSE_RESPONSE->value)
            ->addUInt16(1) // version
            ->addUInt32($correlationId)
            ->addUInt16(0x0001); // responseCode OK
        $content = $response->getContents();
        $this->sendFrame($this->wrapFrame($content));
        $this->close();
    }

    private function handleMetadataUpdate(ReadBuffer $frame): void
    {
        $update = MetadataUpdateResponseV1::fromStreamBuffer($frame);
        if ($this->metadataUpdateCallback instanceof \Closure) {
            ($this->metadataUpdateCallback)($update);
        }
    }

    private function handleConsumerUpdate(ReadBuffer $frame): void
    {
        $query = ConsumerUpdateResponseV1::fromStreamBuffer($frame);
        if (!$query instanceof ConsumerUpdateResponseV1) {
            throw new DeserializationException('Failed to deserialize ConsumerUpdate frame');
        }
        $offsetType = 1;
        $offset = 0;
        if ($this->consumerUpdateCallback instanceof \Closure) {
            [$offsetType, $offset] = ($this->consumerUpdateCallback)($query);
        }
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: $offsetType,
            offset: $offset,
        );
        $reply->withCorrelationId($query->getCorrelationId());
        $content = $this->serializer->serialize($reply);
        $this->sendFrame($this->wrapFrame($content));
    }

    private function wrapFrame(string $content): string
    {
        return (new WriteBuffer())
            ->addUInt32(strlen($content))
            ->addRaw($content)
            ->getContents();
    }

    /**
     * Read a single raw frame from the socket (length-prefixed).
     *
     * @param float $timeout Seconds to wait for data (0.0 = non-blocking poll)
     * @return ReadBuffer|null Parsed frame buffer, or null if no data arrived within the timeout
     * @throws ConnectionException If the socket is not connected, frame exceeds max size, or read error occurs
     */
    public function readFrame(float $timeout = 30.0): ?ReadBuffer
    {
        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot read: socket is not connected");
        }

        $read = [$this->socket];
        $write = null;
        $except = null;

        $timeoutSec = (int) $timeout;
        $timeoutUsec = (int) (($timeout - $timeoutSec) * 1_000_000);

        $ready = socket_select($read, $write, $except, $timeout > 0 ? $timeoutSec : 0, $timeout > 0 ? $timeoutUsec : 0);

        if ($ready === false) {
            throw new ConnectionException('socket_select failed: ' . socket_strerror(socket_last_error($this->socket)));
        }

        if ($ready === 0) {
            return null;
        }

        $sizeData = $this->readBytes(4);
        if ($sizeData === null) {
            return null;
        }

        $sizeUnpacked = unpack('N', $sizeData);
        if ($sizeUnpacked === false) {
            throw new DeserializationException('Failed to unpack frame size');
        }
        $size = $sizeUnpacked[1];

        if ($this->maxFrameSize > 0 && $size > $this->maxFrameSize) {
            $this->close();
            throw new ConnectionException(
                "Frame size {$size} exceeds maximum allowed {$this->maxFrameSize}"
            );
        }

        $frameData = $this->readBytes($size);
        if ($frameData === null) {
            throw new ConnectionException("Failed to read frame data");
        }

        $this->debugFrame('Socket <-', $frameData, keyOffset: 0);

        return new ReadBuffer($frameData);
    }

    /**
     * Log a raw frame at debug level, redacting SASL_AUTHENTICATE frames that
     * contain plaintext credentials ("\0username\0password").
     *
     * Both bin2hex() and the logger call are skipped entirely when debug
     * logging is disabled ($debugLogging is false), so the hot path pays zero
     * cost with NullLogger or a logger filtering out debug records.
     *
     * @param string $prefix    Log message prefix ("Socket -> " or "Socket <-")
     * @param string $frame     Raw frame bytes; in sendFrame this includes the
     *                          4-byte length prefix, in readFrame it does not
     * @param int    $keyOffset Byte offset of the uint16 command key within $frame
     */
    private function debugFrame(string $prefix, string $frame, int $keyOffset): void
    {
        if (!$this->debugLogging) {
            return;
        }

        // Extract the 2-byte big-endian command key at the given offset.
        if (strlen($frame) < $keyOffset + 2) {
            // Frame too short to contain a key — log raw as before.
            $this->logger->debug($prefix . bin2hex($frame));
            return;
        }

        $keyUnpacked = unpack('n', substr($frame, $keyOffset, 2));
        $key = $keyUnpacked !== false ? $keyUnpacked[1] : null;

        if ($key === KeyEnum::SASL_AUTHENTICATE->value) {
            // Never hex-encode: the body contains "\0username\0password".
            $this->logger->debug(sprintf(
                '%s <redacted: SASL_AUTHENTICATE, %d bytes>',
                $prefix,
                strlen($frame)
            ));
            return;
        }

        $this->logger->debug($prefix . bin2hex($frame));
    }

    private function readBytes(int $length): ?string
    {
        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot read: socket is not connected");
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = socket_read($this->socket, $remaining);
            if ($chunk === false) {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_ETIMEDOUT) {
                    return null;
                }
                $this->connected = false;
                throw new ConnectionException("Failed to read from socket: " . socket_strerror($error));
            }
            if ($chunk === '') {
                $this->connected = false;
                throw new ConnectionException("Failed to read from socket: connection closed by peer");
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}
