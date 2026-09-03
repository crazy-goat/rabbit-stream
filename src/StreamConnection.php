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
use CrazyGoat\RabbitStream\Response\CreditResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class StreamConnection
{
    private bool $connected = false;
    private ?\Socket $socket = null;
    private int $correlationId = 0;
    /**
     * Correlated responses read by request() while it was waiting for a different
     * correlation ID (e.g. a nested request() issued from a server-push handler
     * such as a ConsumerUpdate query). Consumed FIFO by readMessage() and by
     * correlation ID by request().
     *
     * @var list<object>
     */
    private array $pendingResponses = [];
    private bool $running = false;
    private readonly bool $debugLogging;

    /** @var array<int, array{onConfirm: callable, onError: callable}> */
    private array $publisherCallbacks = [];
    /** @var array<int, callable> */
    private array $subscriberCallbacks = [];
    /** @var array<int, callable> */
    private array $consumerUpdateHandlers = [];
    /**
     * Per-stream MetadataUpdate handlers: stream name => handler id => handler.
     * Filled by Producer/Consumer so a "stream unavailable" notification reaches
     * exactly the publishers/subscriptions that live on that stream.
     *
     * @var array<string, array<string, callable>>
     */
    private array $metadataUpdateHandlers = [];
    private ?\Closure $metadataUpdateCallback = null;
    private ?\Closure $heartbeatCallback = null;
    private ?\Closure $consumerUpdateCallback = null;

    /**
     * Keyed by protocol key for O(1) isset() lookup instead of in_array()'s
     * linear scan (GitHub #411) — this is checked once per received frame.
     *
     * @var array<int, true>
     */
    private const SERVER_PUSH_KEYS = [
        0x0003 => true, // PublishConfirm
        0x0004 => true, // PublishError
        0x0008 => true, // Deliver
        0x0010 => true, // MetadataUpdate
        0x0016 => true, // Close (server-initiated)
        0x0017 => true, // Heartbeat
        0x001a => true, // ConsumerUpdate
    ];

    public const DEFAULT_MAX_FRAME_SIZE = 8 * 1024 * 1024; // 8MB safety limit

    /**
     * Default SO_RCVTIMEO/SO_SNDTIMEO applied to the socket in connect().
     *
     * Without it every blocking socket_recv()/socket_write() waits forever, so a
     * peer that stops mid-frame hangs the client and every documented timeout
     * (Consumer::read(), readFrame(), readLoop()) silently becomes infinite
     * (GitHub #402). This bounds a single socket call, not a whole operation:
     * the per-call timeouts remain the ones the caller asks for.
     */
    public const DEFAULT_SOCKET_TIMEOUT = 30.0;

    /**
     * Socket-level errors that mean "nothing happened within SO_RCVTIMEO/
     * SO_SNDTIMEO", as opposed to a broken connection. On Linux a timed-out
     * blocking recv()/send() reports EAGAIN (== EWOULDBLOCK); ETIMEDOUT comes
     * from the TCP stack itself.
     *
     * @var list<int>
     */
    private const TRANSIENT_SOCKET_ERRORS = [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK, SOCKET_ETIMEDOUT];

    /**
     * The broker does not enforce frame_max on Deliver frames (0x0008): a chunk is
     * sent whole regardless of the negotiated frame_max, so Deliver frames need a
     * separate, larger cap. 64MB comfortably exceeds chunks observed in practice
     * (multi-megabyte coalesced chunks from a fast producer) while still guarding
     * against a hostile/broken broker sending an unbounded frame.
     */
    public const DEFAULT_MAX_DELIVER_FRAME_SIZE = 64 * 1024 * 1024;

    private float $socketTimeout = self::DEFAULT_SOCKET_TIMEOUT;
    private int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;
    private int $maxDeliverFrameSize = self::DEFAULT_MAX_DELIVER_FRAME_SIZE;
    private int $outgoingMaxFrameSize = 0;

    /**
     * @param string                $host     RabbitMQ stream server hostname
     * @param int                   $port     RabbitMQ stream server port
     * @param LoggerInterface       $logger   PSR-3 logger (defaults to NullLogger)
     * @param BinarySerializerInterface $serializer Serializer for request/response frames
     * @param float                 $socketTimeout Per-socket-call receive/send timeout in
     *                                        seconds (SO_RCVTIMEO/SO_SNDTIMEO), applied in
     *                                        connect(); must be > 0
     * @throws InvalidArgumentException If $socketTimeout is not positive
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 5552,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly BinarySerializerInterface $serializer = new PhpBinarySerializer(),
        float $socketTimeout = self::DEFAULT_SOCKET_TIMEOUT,
    ) {
        $this->setSocketTimeout($socketTimeout);
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

        $this->applySocketTimeout($socket);

        $this->connected = true;
        $this->socket = $socket;
    }

    /**
     * Set the per-socket-call receive/send timeout (SO_RCVTIMEO/SO_SNDTIMEO).
     *
     * Applies immediately when the socket is already open. This is not an
     * operation timeout: it bounds how long one socket_recv()/socket_write()
     * may block with no progress, which is what keeps a stalled peer from
     * hanging the client forever (GitHub #402).
     *
     * @param float $socketTimeout Seconds; must be > 0
     * @throws InvalidArgumentException If $socketTimeout is not positive
     * @throws ConnectionException      If the option cannot be set on an open socket
     */
    public function setSocketTimeout(float $socketTimeout): void
    {
        if ($socketTimeout <= 0) {
            throw new InvalidArgumentException('socketTimeout must be greater than 0');
        }

        $this->socketTimeout = $socketTimeout;

        if ($this->socket instanceof \Socket) {
            $this->applySocketTimeout($this->socket);
        }
    }

    public function getSocketTimeout(): float
    {
        return $this->socketTimeout;
    }

    /**
     * @throws ConnectionException If SO_RCVTIMEO/SO_SNDTIMEO cannot be set — without
     *                             them no read or write is bounded, so the caller must
     *                             not be told the connection is usable
     */
    private function applySocketTimeout(\Socket $socket): void
    {
        $seconds = (int) $this->socketTimeout;
        $option = [
            'sec' => $seconds,
            'usec' => (int) round(($this->socketTimeout - $seconds) * 1_000_000),
        ];

        foreach ([SO_RCVTIMEO, SO_SNDTIMEO] as $name) {
            if (!socket_set_option($socket, SOL_SOCKET, $name, $option)) {
                throw new ConnectionException(
                    'Cannot set socket timeout: ' . socket_strerror(socket_last_error($socket))
                );
            }
        }
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
     * socket_last_error() is sticky: it keeps returning the last error recorded
     * on the socket until it is cleared. The code is therefore cleared right
     * after it is read, and transient codes (a receive timeout, an interrupted
     * call) are not treated as a disconnect — otherwise one timed-out read
     * would report a perfectly healthy connection as dead for the rest of its
     * life, and this method would force it closed (GitHub #391).
     *
     * @return bool True if the socket is valid and has no fatal error state
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
            @socket_clear_error($this->socket);
            if ($error !== 0 && !in_array($error, self::TRANSIENT_SOCKET_ERRORS, true)) {
                // Socket has a fatal error, mark as disconnected
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
     * Set the maximum allowed size in bytes for incoming Deliver frames (key 0x0008).
     *
     * The broker does not enforce the negotiated frame_max on Deliver frames — a
     * stream chunk is sent whole, so this needs its own (larger) cap independent
     * of {@see setMaxFrameSize()}.
     *
     * @param int $maxDeliverFrameSize Maximum Deliver frame size in bytes (0 = no limit)
     * @throws InvalidArgumentException If the value is negative
     */
    public function setMaxDeliverFrameSize(int $maxDeliverFrameSize): void
    {
        if ($maxDeliverFrameSize < 0) {
            throw new InvalidArgumentException(
                "Max deliver frame size must be >= 0 (0 = no limit), got {$maxDeliverFrameSize}"
            );
        }
        $this->maxDeliverFrameSize = $maxDeliverFrameSize;
    }

    /**
     * Get the current maximum allowed size in bytes for incoming Deliver frames.
     *
     * @return int Maximum Deliver frame size (0 = no limit)
     */
    public function getMaxDeliverFrameSize(): int
    {
        return $this->maxDeliverFrameSize;
    }

    /**
     * Set the negotiated outgoing frame size limit in bytes.
     *
     * Frames written via {@see sendFrame()} larger than this are rejected up
     * front with an {@see InvalidArgumentException} before anything is written
     * to the socket, instead of being written and having the broker close the
     * connection.
     *
     * @param int $outgoingMaxFrameSize Maximum outgoing frame size in bytes (0 = no limit)
     * @throws InvalidArgumentException If the value is negative
     */
    public function setOutgoingMaxFrameSize(int $outgoingMaxFrameSize): void
    {
        if ($outgoingMaxFrameSize < 0) {
            throw new InvalidArgumentException(
                "Outgoing max frame size must be >= 0 (0 = no limit), got {$outgoingMaxFrameSize}"
            );
        }
        $this->outgoingMaxFrameSize = $outgoingMaxFrameSize;
    }

    /**
     * Get the current negotiated outgoing frame size limit in bytes.
     *
     * @return int Maximum outgoing frame size (0 = no limit)
     */
    public function getOutgoingMaxFrameSize(): int
    {
        return $this->outgoingMaxFrameSize;
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
        unset($this->consumerUpdateHandlers[$subscriptionId]);
    }

    /**
     * Register a per-subscription handler for ConsumerUpdate queries (single
     * active consumer activation/deactivation, super-stream rebalance).
     *
     * Dispatch order in handleConsumerUpdate(): a registered per-subscription
     * handler takes priority over the global onConsumerUpdate() callback,
     * which in turn takes priority over the "none" (keep current position)
     * default.
     *
     * @param int      $subscriptionId Subscription ID as declared with the server
     * @param callable $handler        Called with (ConsumerUpdateResponseV1 $update): ?OffsetSpec.
     *                                 Returning null means "none" (offsetType 0).
     */
    public function registerConsumerUpdateHandler(int $subscriptionId, callable $handler): void
    {
        $this->consumerUpdateHandlers[$subscriptionId] = $handler;
    }

    /**
     * Remove a previously registered per-subscription ConsumerUpdate handler.
     *
     * @param int $subscriptionId Subscription ID to unregister
     */
    public function unregisterConsumerUpdateHandler(int $subscriptionId): void
    {
        unset($this->consumerUpdateHandlers[$subscriptionId]);
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
     * The global callback is invoked for every MetadataUpdate, after the
     * per-stream handlers registered with registerMetadataUpdateHandler().
     *
     * @param callable $callback Called with (MetadataUpdateResponseV1 $update) on topology changes
     */
    public function onMetadataUpdate(callable $callback): void
    {
        $this->metadataUpdateCallback = \Closure::fromCallable($callback);
    }

    /**
     * Register a per-stream handler for MetadataUpdate frames.
     *
     * The broker pushes MetadataUpdate when a stream becomes unavailable (it was
     * deleted, or its leader moved) and drops every publisher and subscription
     * that was declared on it. Producer and Consumer register a handler here so
     * they can mark themselves stale and re-declare/re-subscribe transparently.
     *
     * @param string   $stream    Stream name the handler is interested in
     * @param string   $handlerId Unique id per registration (e.g. "publisher-3"), used to unregister
     * @param callable $handler   Called with (MetadataUpdateResponseV1 $update)
     */
    public function registerMetadataUpdateHandler(string $stream, string $handlerId, callable $handler): void
    {
        $this->metadataUpdateHandlers[$stream][$handlerId] = $handler;
    }

    /**
     * Remove a per-stream MetadataUpdate handler registered with registerMetadataUpdateHandler().
     */
    public function unregisterMetadataUpdateHandler(string $stream, string $handlerId): void
    {
        unset($this->metadataUpdateHandlers[$stream][$handlerId]);
        if (isset($this->metadataUpdateHandlers[$stream]) && $this->metadataUpdateHandlers[$stream] === []) {
            unset($this->metadataUpdateHandlers[$stream]);
        }
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
     * @throws ConnectionException      If the socket is not connected or a write error occurs
     * @throws InvalidArgumentException If the frame exceeds the negotiated outgoing frame size limit
     * @throws TimeoutException         If the socket is not ready for writing within the timeout
     */
    public function sendFrame(string $frame, ?float $timeout = null): int
    {
        if ($this->outgoingMaxFrameSize > 0) {
            // $frame includes the 4-byte length prefix added by wrapFrame(); the
            // negotiated frame_max applies to the payload only.
            $payloadSize = strlen($frame) - 4;
            if ($payloadSize > $this->outgoingMaxFrameSize) {
                throw new InvalidArgumentException(
                    "Frame size {$payloadSize} exceeds negotiated maximum frame size of " .
                    "{$this->outgoingMaxFrameSize}"
                );
            }
        }

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

        return $this->writeAll($frame);
    }

    /**
     * Write every byte of $frame, looping over partial writes.
     *
     * socket_write() may accept fewer bytes than requested (SO_SNDBUF pressure,
     * a large batch Publish frame, a signal). Sending the rest is not optional:
     * the broker reads the next 4 bytes as a frame length, so a frame left
     * half-written makes it parse payload bytes as framing — silent data loss
     * for the publisher, protocol error or a multi-gigabyte length for the
     * broker (GitHub #389).
     *
     * A frame that cannot be finished leaves the peer mid-frame, so there is no
     * way back: the connection is closed rather than left desynchronised.
     *
     * @return int Number of bytes written (always strlen($frame) on success)
     * @throws ConnectionException If the write fails or a partial frame cannot be completed
     * @throws TimeoutException    If nothing at all could be written before SO_SNDTIMEO expired
     */
    private function writeAll(string $frame): int
    {
        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot write: socket is not connected");
        }

        $total = strlen($frame);
        $sent = 0;

        while ($sent < $total) {
            try {
                $written = socket_write(
                    $this->socket,
                    $sent === 0 ? $frame : substr($frame, $sent),
                    $total - $sent
                );
            } catch (\Error $e) {
                $this->connected = false;
                throw new ConnectionException("Failed to write to socket: " . $e->getMessage(), $e->getCode(), $e);
            }

            if ($written === false || $written === 0) {
                $error = $written === false ? socket_last_error($this->socket) : 0;
                socket_clear_error($this->socket);

                if ($error === SOCKET_EINTR) {
                    continue;
                }

                if ($written === 0 || in_array($error, self::TRANSIENT_SOCKET_ERRORS, true)) {
                    if ($sent === 0) {
                        // Nothing left the client: the frame was never started,
                        // so the caller can safely retry it.
                        throw new TimeoutException(sprintf(
                            'Write timed out after %.1fs: no bytes of a %d byte frame could be sent',
                            $this->socketTimeout,
                            $total
                        ));
                    }
                    $this->close();
                    throw new ConnectionException(sprintf(
                        'Write timed out after %.1fs with a partial frame on the wire (%d of %d bytes); ' .
                        'connection closed because the broker cannot resynchronise mid-frame',
                        $this->socketTimeout,
                        $sent,
                        $total
                    ));
                }

                $this->connected = false;
                throw new ConnectionException("Failed to write to socket: " . socket_strerror($error));
            }

            $sent += $written;
        }

        return $sent;
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
        if ($this->pendingResponses !== []) {
            return array_shift($this->pendingResponses);
        }

        return $this->readResponse($timeout, null);
    }

    /**
     * Send a correlated request and return its matching response.
     *
     * Unlike sendMessage()+readMessage(), this matches the reply by correlation
     * ID, so it is safe to call re-entrantly from a server-push handler (for
     * example a ConsumerUpdate handler querying the stored offset while an outer
     * request() is still waiting for its own SubscribeResponse). Responses that
     * belong to another in-flight request are parked and handed to that
     * request (or to the next readMessage()) instead of being misattributed.
     *
     * @param object $request Request object implementing ToStreamBufferInterface and CorrelationInterface
     * @param float  $timeout Seconds to wait for the response
     * @throws InvalidArgumentException If the request carries no correlation ID
     * @throws ConnectionException|DeserializationException|ProtocolException|TimeoutException See readMessage()
     */
    public function request(object $request, float $timeout = 30.0): object
    {
        if (!$request instanceof CorrelationInterface) {
            throw new InvalidArgumentException('request() requires a correlated request; use sendMessage()');
        }
        $this->sendMessage($request, $timeout);

        return $this->readResponse($timeout, $request->getCorrelationId());
    }

    private function readResponse(float $timeout, ?int $expectedCorrelationId): object
    {
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        while (true) {
            if (!$this->connected) {
                throw new ConnectionException("Connection closed");
            }

            // A nested request() (issued from a server-push handler dispatched
            // below) may already have parked the response we are waiting for.
            if ($expectedCorrelationId !== null) {
                $parked = $this->takePendingResponse($expectedCorrelationId);
                if ($parked !== null) {
                    return $parked;
                }
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

            if (isset(self::SERVER_PUSH_KEYS[$key])) {
                $this->dispatchServerPush($frame);

                // Connection may have been closed by server-initiated close
                if (!$this->connected) {
                    throw new ConnectionException("Connection closed by server");
                }

                continue;
            }

            $response = $this->serializer->deserialize($frame->getRemainingBytes());
            if ($expectedCorrelationId === null) {
                return $response;
            }

            if (!$response instanceof CorrelationInterface) {
                // A response frame without a correlation ID (in practice a Credit
                // error, which the broker only sends for a rejected Credit request,
                // e.g. after a single-active-consumer handover) cannot be the reply
                // we are waiting for. Log and keep reading.
                $this->logger->warning('Unsolicited response received while awaiting correlated reply', [
                    'response' => $response::class,
                    'details' => $response instanceof CreditResponseV1
                        ? [
                            'subscriptionId' => $response->getSubscriptionId(),
                            'responseCode' => $response->getResponseCode(),
                        ]
                        : [],
                ]);
                continue;
            }

            if ($response->getCorrelationId() !== $expectedCorrelationId) {
                // Belongs to another in-flight request (outer or nested) — park it.
                $this->pendingResponses[] = $response;
                continue;
            }

            return $response;
        }
    }

    private function takePendingResponse(int $correlationId): ?object
    {
        foreach ($this->pendingResponses as $index => $pending) {
            if ($pending instanceof CorrelationInterface && $pending->getCorrelationId() === $correlationId) {
                array_splice($this->pendingResponses, $index, 1);
                return $pending;
            }
        }
        return null;
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
     * @return int Number of server-push frames dispatched; 0 means the loop ended
     *             on timeout, stop() or disconnect without handling any frame
     * @throws ConnectionException If the socket is not connected
     */
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int
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

            // socket_select() already confirmed the socket is readable above;
            // avoid a second, redundant select per frame (see readFrameNoWait()).
            $frame = $this->readFrameNoWait();
            if (!$frame instanceof \CrazyGoat\RabbitStream\Buffer\ReadBuffer) {
                continue;
            }

            $key = $frame->peekUint16();

            if (isset(self::SERVER_PUSH_KEYS[$key])) {
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
                // Count discarded frames toward maxFrames so unexpected traffic
                // (e.g. CreditResponse) cannot make readLoop(maxFrames: N) unbounded.
                $dispatched++;
            }

            if ($maxFrames !== null && $dispatched >= $maxFrames) {
                break;
            }
        }

        $this->running = false;

        return $dispatched;
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
        if (!$update instanceof MetadataUpdateResponseV1) {
            throw new DeserializationException('Failed to deserialize MetadataUpdate frame');
        }
        $this->logger->warning('MetadataUpdate: stream unavailable', [
            'stream' => $update->getStream(),
            'code' => sprintf('0x%04x', $update->getCode()),
        ]);
        // Copy: a handler may (un)register handlers for this stream while we iterate.
        foreach ($this->metadataUpdateHandlers[$update->getStream()] ?? [] as $handler) {
            $handler($update);
        }
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
        $offsetType = OffsetSpec::TYPE_NONE;
        $offset = 0;

        $subscriptionHandler = $this->consumerUpdateHandlers[$query->getSubscriptionId()] ?? null;
        if ($subscriptionHandler !== null) {
            $offsetSpec = $subscriptionHandler($query);
            if ($offsetSpec !== null) {
                [$offsetType, $offset] = [$offsetSpec->getType(), $offsetSpec->getValue() ?? 0];
            }
        } elseif ($this->consumerUpdateCallback instanceof \Closure) {
            [$offsetType, $offset] = ($this->consumerUpdateCallback)($query);
        }

        if ($offsetType < 0 || $offsetType > 5) {
            throw new InvalidArgumentException(
                "Invalid ConsumerUpdate reply offset type: {$offsetType} (must be 0-5)"
            );
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
        // Direct pack()+concat instead of a WriteBuffer object: this runs once
        // per outgoing message (and once per heartbeat/close-response reply).
        return pack('N', strlen($content)) . $content;
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

        return $this->readFrameNoWait();
    }

    /**
     * Read a single raw frame from the socket without first calling socket_select().
     *
     * Callers must already know the socket is readable (or be prepared to block on
     * the underlying socket_read() calls) — this exists so readLoop(), which already
     * performs its own socket_select() before every frame, does not pay for a second,
     * redundant select per frame.
     *
     * The frame is decoded as Size(uint32) + Key(uint16) + rest of payload: the key
     * is read separately from the remaining payload so that the size cap can be
     * chosen based on the frame's key — Deliver frames (0x0008) are not capped by
     * the negotiated frame_max, since the broker sends stream chunks whole
     * regardless of frame_max; they use {@see $maxDeliverFrameSize} instead.
     *
     * @return ReadBuffer|null Parsed frame buffer, or null if no data arrived
     * @throws ConnectionException If the socket is not connected, frame exceeds max size, or read error occurs
     */
    private function readFrameNoWait(): ?ReadBuffer
    {
        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot read: socket is not connected");
        }

        // The only read allowed to come back empty-handed: at this point the
        // socket is at a frame boundary, so "no data yet" is not a desync.
        $sizeData = $this->readBytes(4);
        if ($sizeData === null) {
            return null;
        }

        $sizeUnpacked = unpack('N', $sizeData);
        if ($sizeUnpacked === false) {
            throw new DeserializationException('Failed to unpack frame size');
        }
        $size = $sizeUnpacked[1];

        if ($size < 2) {
            throw new DeserializationException("Frame size {$size} is too small to contain a key");
        }

        // Fast path: a frame that fits under BOTH caps is read in one piece, no
        // key peek and no concatenation. Only a frame exceeding the smaller cap
        // needs its key inspected, because Deliver frames (0x0008) get their own
        // cap — the broker does not enforce frame_max on them.
        $fastCap = match (true) {
            $this->maxFrameSize <= 0 => $this->maxDeliverFrameSize,
            $this->maxDeliverFrameSize <= 0 => $this->maxFrameSize,
            default => min($this->maxFrameSize, $this->maxDeliverFrameSize),
        };
        if ($fastCap <= 0 || $size <= $fastCap) {
            $frameData = $this->readBytes($size, mustComplete: true);
            if ($frameData === null) {
                throw new ConnectionException("Failed to read frame data");
            }
        } else {
            $keyData = $this->readBytes(2, mustComplete: true);
            if ($keyData === null) {
                throw new ConnectionException("Failed to read frame key");
            }

            $keyUnpacked = unpack('n', $keyData);
            $key = $keyUnpacked !== false ? $keyUnpacked[1] : null;

            $cap = $key === KeyEnum::DELIVER->value ? $this->maxDeliverFrameSize : $this->maxFrameSize;

            if ($cap > 0 && $size > $cap) {
                $this->close();
                throw new ConnectionException(
                    "Frame size {$size} exceeds maximum allowed {$cap}"
                );
            }

            $remainingData = $this->readBytes($size - 2, mustComplete: true);
            if ($remainingData === null) {
                throw new ConnectionException("Failed to read frame data");
            }

            $frameData = $keyData . $remainingData;
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

    /**
     * Read exactly $length bytes from the socket into a single buffer.
     *
     * Uses socket_recv() with MSG_WAITALL so the kernel fills as much of the
     * request as it can in one call instead of the previous socket_read()
     * loop, which issued one syscall (and one string realloc via `.=`) per
     * available chunk — for an 8MB Deliver frame that could be hundreds of
     * short reads. MSG_WAITALL still returns short on a signal (EINTR) or a
     * partial receive before the full length is available, so the loop below
     * keeps issuing recv() for the remainder; a `''` chunk still means the
     * peer closed the connection, and SOCKET_ETIMEDOUT still yields null,
     * matching the previous semantics exactly.
     */
    /**
     * Read exactly $length bytes from the socket.
     *
     * On a receive timeout (SO_RCVTIMEO, see {@see self::DEFAULT_SOCKET_TIMEOUT})
     * the outcome depends on how much of the read had already succeeded:
     *
     *  - nothing consumed yet and $mustComplete is false — the socket is at a
     *    frame boundary, so null is returned and the caller may simply try again;
     *  - anything already consumed, or $mustComplete — those bytes cannot be
     *    pushed back onto the socket, so the frame can never be assembled. The
     *    old code returned null here and dropped them, after which the next read
     *    took mid-frame payload for a frame length and desynchronised the
     *    connection permanently (GitHub #390). The connection is now closed with
     *    an explicit error instead.
     *
     * @param int  $length       Number of bytes to read (0 returns '')
     * @param bool $mustComplete True when the caller is already mid-frame, so a
     *                           short read is unrecoverable rather than benign
     * @return string|null The bytes read, or null if no byte arrived at a frame boundary
     * @throws ConnectionException If the socket is not connected, the peer closed it,
     *                             the read fails, or an incomplete frame timed out
     */
    private function readBytes(int $length, bool $mustComplete = false): ?string
    {
        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot read: socket is not connected");
        }

        if ($length === 0) {
            return '';
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = '';
            $read = socket_recv($this->socket, $chunk, $remaining, MSG_WAITALL);

            if ($read === false) {
                $error = socket_last_error($this->socket);
                socket_clear_error($this->socket);
                if ($error === SOCKET_EINTR) {
                    continue;
                }
                if (in_array($error, self::TRANSIENT_SOCKET_ERRORS, true)) {
                    if (!$mustComplete && $data === '') {
                        return null;
                    }
                    $this->close();
                    throw new ConnectionException(sprintf(
                        'Read timed out after %.1fs with an incomplete frame (%d of %d bytes); ' .
                        'connection closed because the byte stream can no longer be resynchronised',
                        $this->socketTimeout,
                        strlen($data),
                        $length
                    ));
                }
                $this->connected = false;
                throw new ConnectionException("Failed to read from socket: " . socket_strerror($error));
            }

            if ($read === 0) {
                $this->connected = false;
                throw new ConnectionException("Failed to read from socket: connection closed by peer");
            }

            $data .= $chunk;
            $remaining -= $read;
        }

        return $data;
    }
}
