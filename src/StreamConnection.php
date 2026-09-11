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
use CrazyGoat\RabbitStream\VO\TlsConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class StreamConnection
{
    private bool $connected = false;
    /**
     * Underlying PHP stream (tcp:// or ssl://). Streams (not ext-sockets) are
     * used for BOTH transports because TLS in PHP is only available through
     * stream_socket_client('ssl://...'); a \Socket cannot be upgraded to TLS
     * (socket_import_stream() reads ciphertext below the SSL layer).
     *
     * @var resource|null
     */
    private $stream;

    /**
     * The open connection stream, or an exception if it is gone.
     *
     * @return resource
     */
    private function requireStream()
    {
        if ($this->stream === null || !is_resource($this->stream)) {
            throw new ConnectionException("Cannot read: socket is not connected");
        }

        return $this->stream;
    }
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
     * Outgoing frame size ceiling enforced by the broker until Open completes.
     *
     * RabbitMQ 4.3 enforces a low frame_max on incoming frames (8192 bytes by
     * default, configurable server-side via stream.initial_frame_max) for the
     * whole handshake, regardless of the value negotiated at Tune. A pre-Open
     * frame above it makes the broker drop the connection with an opaque
     * "Frame too large" instead of a client-side error, so the client seeds its
     * outgoing cap with this value before the first handshake frame and lifts it
     * to the negotiated frame_max only once OpenResponseV1 succeeded (see #379).
     *
     * Two distinct windows, two distinct exceptions:
     *  - pre-Open oversize (before OpenResponseV1) is rejected by
     *    {@see sendMessage()} with a {@see ProtocolException} naming the
     *    offending command, using {@see setPreOpenMaxFrameSize()};
     *  - post-Open oversize is rejected by {@see sendFrame()} with an
     *    {@see InvalidArgumentException}, using {@see setOutgoingMaxFrameSize()}.
     *
     * A bare StreamConnection defaults to 0 (no limit) on both caps; the high
     * level {@see \CrazyGoat\RabbitStream\Client\Connection} seeds them.
     */
    public const DEFAULT_INITIAL_FRAME_SIZE = 8192;

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
     * Pre-Open outgoing frame size ceiling (0 = no client-side check).
     *
     * Applies only until Open completes, mirroring the broker's
     * stream.initial_frame_max, and must be lifted by the caller (pass 0)
     * once the negotiated frame_max takes over. Kept separate from
     * {@see $outgoingMaxFrameSize} so a pre-Open oversize can be reported as a
     * {@see ProtocolException} naming the command without changing the post-Open
     * {@see InvalidArgumentException} contract (see #379).
     */
    private int $preOpenMaxFrameSize = 0;

    /**
     * @param string                $host     RabbitMQ stream server hostname
     * @param int                   $port     RabbitMQ stream server port
     * @param LoggerInterface       $logger   PSR-3 logger (defaults to NullLogger)
     * @param BinarySerializerInterface $serializer Serializer for request/response frames
     * @param float                 $socketTimeout Per-socket-call receive/send timeout in
     *                                        seconds (SO_RCVTIMEO/SO_SNDTIMEO), applied in
     *                                        connect(); must be > 0
     * @param TlsConfig|null        $tls     TLS transport options; null (default) uses
     *                                        the plaintext tcp:// transport. Passing a
     *                                        config selects the ssl:// transport (TLS
     *                                        stream listener, port 5551) — GitHub #400
     * @throws InvalidArgumentException If $socketTimeout is not positive
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 5552,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly BinarySerializerInterface $serializer = new PhpBinarySerializer(),
        float $socketTimeout = self::DEFAULT_SOCKET_TIMEOUT,
        private readonly ?TlsConfig $tls = null,
    ) {
        $this->setSocketTimeout($socketTimeout);
        // Resolve once at construction: avoids paying bin2hex() cost on every
        // frame when the logger won't emit debug records (NullLogger default).
        $this->debugLogging = !$logger instanceof NullLogger;
    }

    /**
     * Open the connection to the RabbitMQ stream server.
     *
     * Uses stream_socket_client() so both the plaintext tcp:// transport and the
     * TLS ssl:// transport (GitHub #400) share one I/O path — see the $stream
     * property docblock for why ext-sockets cannot be kept for TLS.
     *
     * @throws ConnectionException If the socket cannot be created or connected,
     *                             or the TLS handshake fails
     */
    public function connect(): void
    {
        $useTls = $this->tls instanceof TlsConfig;
        $scheme = $useTls ? 'ssl' : 'tcp';
        $context = stream_context_create($useTls ? $this->tls->toStreamContext() : []);

        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $this->host, $this->port),
            $errorCode,
            $errorMessage,
            $this->socketTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new ConnectionException(
                "Cannot connect to {$scheme}://{$this->host}:{$this->port}: " .
                "{$errorMessage} ({$errorCode})"
            );
        }

        // The stream is kept non-blocking: every read and write is driven by an
        // explicit stream_select() bounded by $socketTimeout, which gives the
        // same "no I/O call may block unboundedly" guarantee as the previous
        // SO_RCVTIMEO/SO_SNDTIMEO set-up (GitHub #402). stream_set_timeout()
        // could not be used because it bounds reads but NOT blocking writes.
        if (!stream_set_blocking($stream, false)) {
            fclose($stream);
            throw new ConnectionException('Cannot set the connection to non-blocking mode');
        }

        if ($useTls) {
            $this->enableCrypto($stream);
        }

        $this->connected = true;
        $this->stream = $stream;
    }

    /**
     * Perform the TLS handshake on a non-blocking stream, bounded by
     * $socketTimeout (GitHub #400).
     *
     * stream_socket_enable_crypto() on a blocking stream would wait on the
     * default INI timeout (or longer) if the broker stalls mid-handshake,
     * which would weaken the #402 "no unbounded I/O" guarantee. Instead, the
     * handshake is driven like every other I/O call: retry while it reports
     * progress, wait between attempts with stream_select(), and give up once
     * the deadline expires.
     *
     * @param resource $stream Non-blocking stream to upgrade to TLS
     * @throws ConnectionException If the handshake fails or exceeds $socketTimeout
     */
    private function enableCrypto($stream): void
    {
        $endpoint = sprintf('%s:%d', $this->host, $this->port);
        $deadline = microtime(true) + $this->socketTimeout;

        while (true) {
            $result = @stream_socket_enable_crypto($stream, true);
            if ($result === true) {
                return;
            }

            $opensslError = $this->lastOpenSslError();
            if ($opensslError !== null) {
                fclose($stream);
                throw new ConnectionException("TLS handshake failed with {$endpoint}: {$opensslError}");
            }

            // No OpenSSL error queued: the handshake needs more data
            // (would-block) and must be retried once the socket is ready.
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                fclose($stream);
                throw new ConnectionException(sprintf(
                    'TLS handshake with %s timed out after %.1fs',
                    $endpoint,
                    $this->socketTimeout
                ));
            }

            $read = [$stream];
            $write = [$stream];
            $except = null;
            // The handshake may need to read or write; listen for both.
            @stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - (int) $remaining) * 1_000_000)
            );
            // On timeout (0) or interruption the loop re-enters and either
            // hits the deadline check above or retries the handshake.
        }
    }

    /**
     * Drain the OpenSSL error queue, keeping the details of the most recent
     * failure (bad cafile, hostname mismatch, expired cert, ...).
     */
    private function lastOpenSslError(): ?string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return $messages === [] ? null : implode('; ', array_reverse($messages));
    }

    /**
     * Whether the last failed stream_select() was only interrupted by a signal
     * (EINTR) rather than being a real failure — safe to retry (GitHub #402
     * retained the old recv()/send() EINTR behaviour on the select-based path).
     */
    private function selectWasInterrupted(): bool
    {
        $error = error_get_last();

        return $error !== null
            && stripos($error['message'], 'interrupted system call') !== false;
    }

    /**
     * Set the per-I/O-call timeout.
     *
     * Applies immediately when the connection is already open. This is not an
     * operation timeout: it bounds how long one read/write may wait with no
     * progress (enforced by the stream_select() loops in readBytes()/writeAll()),
     * which is what keeps a stalled peer from hanging the client forever
     * (GitHub #402).
     *
     * @param float $socketTimeout Seconds; must be > 0
     * @throws InvalidArgumentException If $socketTimeout is not positive
     */
    public function setSocketTimeout(float $socketTimeout): void
    {
        if ($socketTimeout <= 0) {
            throw new InvalidArgumentException('socketTimeout must be greater than 0');
        }

        $this->socketTimeout = $socketTimeout;
    }

    public function getSocketTimeout(): float
    {
        return $this->socketTimeout;
    }

    /**
     * Close the TCP socket connection.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function close(): void
    {
        if ($this->connected && $this->stream !== null && is_resource($this->stream)) {
            try {
                fclose($this->stream);
            } catch (\Throwable) {
                // Stream may already be closed, ignore
            }
            $this->stream = null;
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
     * Check whether the underlying connection is currently established and usable.
     *
     * Unlike the previous ext-sockets implementation, only resource validity is
     * checked: PHP streams have no equivalent of the sticky socket_last_error()
     * probe, so no fatal error state can be detected here. A dead peer is
     * surfaced by the next read/write instead (GitHub #391).
     *
     * @return bool True if the underlying stream resource is still valid
     */
    public function isConnected(): bool
    {
        if (!$this->connected) {
            return false;
        }

        if ($this->stream === null || !is_resource($this->stream)) {
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
     * This setter is pure: it does not touch the pre-Open ceiling. When the
     * pre-Open window ends (after Open), call
     * {@see setPreOpenMaxFrameSize()} with 0 explicitly.
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
     * Set the outgoing frame size ceiling enforced until Open completes.
     *
     * RabbitMQ caps incoming frames at stream.initial_frame_max (8192 bytes by
     * default) for the whole handshake, regardless of the frame_max negotiated
     * at Tune. A request serialized above this limit is rejected by
     * {@see sendMessage()} with a {@see ProtocolException} naming the command,
     * before anything is written to the socket, instead of letting the broker
     * drop the connection with an opaque "Frame too large" (see #379).
     *
     * The ceiling stays in force for the connection until the caller ends the
     * pre-Open window by calling this method with 0 — do that after Open
     * completes, when the broker's stream.initial_frame_max no longer applies.
     *
     * @param int $size Pre-Open maximum frame size in bytes (0 = no client-side check)
     * @throws InvalidArgumentException If the value is negative
     */
    public function setPreOpenMaxFrameSize(int $size): void
    {
        if ($size < 0) {
            throw new InvalidArgumentException(
                "Pre-Open max frame size must be >= 0 (0 = no limit), got {$size}"
            );
        }
        $this->preOpenMaxFrameSize = $size;
    }

    /**
     * Get the current pre-Open outgoing frame size ceiling in bytes.
     *
     * @return int Pre-Open maximum frame size (0 = no client-side check)
     */
    public function getPreOpenMaxFrameSize(): int
    {
        return $this->preOpenMaxFrameSize;
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
     * @throws ProtocolException        If the serialized request exceeds the pre-Open frame size ceiling
     *                                  ({@see setPreOpenMaxFrameSize()} — the broker enforces
     *                                  stream.initial_frame_max until Open completes)
     * @throws TimeoutException         If the write times out
     */
    public function sendMessage(object $request, ?float $timeout = null): void
    {
        if ($request instanceof CorrelationInterface) {
            // The correlation id must be assigned before serialization so it
            // lands on the wire. This means an oversized pre-Open request burns
            // one id before the guard below rejects it; that gap is harmless
            // (ids only need to be unique per in-flight request) and is
            // preferable to serializing twice or breaking correlation.
            $this->correlationId++;
            $request->withCorrelationId($this->correlationId);
        }

        $content = $this->serializer->serialize($request);
        $payloadSize = strlen($content);

        // Pre-Open ceiling: command-aware so the error names the offending
        // command, instead of the broker closing the socket with an opaque
        // "Frame too large" (#379). Post-Open frames are bounded by
        // sendFrame()'s negotiated cap and raise InvalidArgumentException.
        if ($this->preOpenMaxFrameSize > 0 && $payloadSize > $this->preOpenMaxFrameSize) {
            throw new ProtocolException(sprintf(
                '%s payload of %d bytes exceeds the pre-Open maximum frame size of %d bytes '
                . '(the broker enforces stream.initial_frame_max until Open completes)',
                $request::class,
                $payloadSize,
                $this->preOpenMaxFrameSize
            ));
        }

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
     *                                  ({@see setOutgoingMaxFrameSize()} — post-Open only; a pre-Open
     *                                  oversize is a {@see ProtocolException} from {@see sendMessage()})
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

        $stream = $this->requireStream();

        // If timeout is specified, wait for the connection to be ready for writing
        if ($timeout !== null && $timeout > 0) {
            $deadline = microtime(true) + $timeout;

            $read = null;
            $write = [$stream];
            $except = null;

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException("Write timeout: connection not ready for writing");
            }

            $timeoutSec = (int) $remaining;
            $timeoutUsec = (int) (($remaining - $timeoutSec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($ready === false) {
                throw new ConnectionException("stream_select failed while waiting for write readiness");
            }

            if ($ready === 0) {
                throw new TimeoutException("Write timeout: connection not ready for writing");
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
        $stream = $this->requireStream();

        $total = strlen($frame);
        $sent = 0;
        $deadline = microtime(true) + $this->socketTimeout;

        while ($sent < $total) {
            $write = [$stream];
            $read = null;
            $except = null;

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->writeTimeout($sent, $total);
            }

            $ready = @stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - (int) $remaining) * 1_000_000)
            );

            if ($ready === false) {
                if ($this->selectWasInterrupted()) {
                    continue;
                }
                throw new ConnectionException('stream_select failed while writing');
            }
            if ($ready === 0) {
                $this->writeTimeout($sent, $total);
            }

            // select() said writable, so on a non-blocking stream fwrite() will
            // accept at least one byte and never block.
            $written = @fwrite($stream, $sent === 0 ? $frame : substr($frame, $sent));

            if ($written === false || $written === 0) {
                $this->connected = false;
                throw new ConnectionException(
                    'Failed to write to socket: peer closed the connection or write error. ' .
                    'On an ssl:// transport a false/0 fwrite() can also indicate a temporary ' .
                    'TLS would-block or renegotiation state that select() already reported as ' .
                    'writable — check stream_get_meta_data() for the current state'
                );
            }

            $sent += $written;
        }

        return $sent;
    }

    /**
     * Report a write timeout: TimeoutException when the frame was never started
     * (the caller may retry it), ConnectionException plus close() when a partial
     * frame is already on the wire (GitHub #389).
     */
    private function writeTimeout(int $sent, int $total): void
    {
        if ($sent === 0) {
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
     * @param int|null   $maxFrames Maximum number of frames to process (null = unlimited)
     * @param float|null $timeout   Maximum wall-clock time in seconds (null = unlimited)
     * @return int Number of frames processed (dispatched server-push frames plus any
     *             discarded non-server-push frames); 0 means the loop ended
     *             on timeout, stop() or disconnect without handling any frame
     * @throws ConnectionException If the socket is not connected
     */
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int
    {
        $stream = $this->requireStream();

        $this->running = true;
        $dispatched = 0;
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while ($this->running && $this->connected) {
            // Check if timeout has expired
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $read = [$stream];
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

            $ready = @stream_select($read, $write, $except, $selectTimeoutSec, $selectTimeoutUsec);

            if ($ready === false) {
                throw new ConnectionException('stream_select failed in readLoop');
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
                $dispatched++;
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
        $stream = $this->requireStream();

        $read = [$stream];
        $write = null;
        $except = null;

        $timeoutSec = (int) $timeout;
        $timeoutUsec = (int) (($timeout - $timeoutSec) * 1_000_000);

        $ready = @stream_select(
            $read,
            $write,
            $except,
            $timeout > 0 ? $timeoutSec : 0,
            $timeout > 0 ? $timeoutUsec : 0
        );

        if ($ready === false) {
            throw new ConnectionException('stream_select failed while waiting for frame data');
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
        $this->requireStream();

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
     * Read exactly $length bytes from the connection.
     *
     * Streams have no MSG_WAITALL equivalent: fread() returns whatever is
     * currently available, so short reads are accumulated in a loop, matching
     * the semantics of the previous recv()-based implementation.
     *
     * On a receive timeout (bounded by $socketTimeout via the stream_select()
     * loops, see {@see self::DEFAULT_SOCKET_TIMEOUT}) the outcome depends on
     * how much of the read had already succeeded:
     *
     *  - nothing consumed yet and $mustComplete is false — the connection is at a
     *    frame boundary, so null is returned and the caller may simply try again;
     *  - anything already consumed, or $mustComplete — those bytes cannot be
     *    pushed back onto the connection, so the frame can never be assembled. The
     *    old code returned null here and dropped them, after which the next read
     *    took mid-frame payload for a frame length and desynchronised the
     *    connection permanently (GitHub #390). The connection is now closed with
     *    an explicit error instead.
     *
     * @param int  $length       Number of bytes to read (0 returns '')
     * @param bool $mustComplete True when the caller is already mid-frame, so a
     *                           short read is unrecoverable rather than benign
     * @return ?string The bytes read, or null if no byte arrived at a frame boundary
     * @throws ConnectionException If the connection is not connected, the peer closed it,
     *                             the read fails, or an incomplete frame timed out
     */
    private function readBytes(int $length, bool $mustComplete = false): ?string
    {
        $stream = $this->requireStream();

        if ($length === 0) {
            return '';
        }

        $data = '';
        $remaining = $length;
        $deadline = microtime(true) + $this->socketTimeout;

        while ($remaining > 0) {
            $read = [$stream];
            $write = null;
            $except = null;

            $remainingTime = $deadline - microtime(true);
            if ($remainingTime <= 0 && $this->readTimeout($data, $length, $mustComplete)) {
                return null;
            }

            $ready = @stream_select(
                $read,
                $write,
                $except,
                (int) $remainingTime,
                (int) (($remainingTime - (int) $remainingTime) * 1_000_000)
            );

            if ($ready === false) {
                if ($this->selectWasInterrupted()) {
                    continue;
                }
                throw new ConnectionException('stream_select failed while reading');
            }
            if ($ready === 0 && $this->readTimeout($data, $length, $mustComplete)) {
                return null;
            }

            // Non-blocking: fread() returns whatever plaintext is available and
            // never blocks. On an ssl:// transport a readable select() state does
            // not yet guarantee decodable plaintext (a TLS record may still be
            // incomplete), so an empty read here is retried until the deadline
            // rather than treated as EOF.
            $chunk = @fread($stream, $remaining);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                if ($meta['eof']) {
                    $this->connected = false;
                    throw new ConnectionException("Failed to read from socket: connection closed by peer");
                }

                continue;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Report a read timeout. Returns true when the read was at a frame boundary
     * (nothing consumed, $mustComplete false) and the caller may simply try
     * again; mid-frame the consumed bytes cannot be pushed back (GitHub #390),
     * so the connection is closed with an explicit error.
     */
    private function readTimeout(string $data, int $length, bool $mustComplete): bool
    {
        if (!$mustComplete && $data === '') {
            return true;
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
}
