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
use CrazyGoat\RabbitStream\VO\CommandVersion;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishingError;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class StreamConnection
{
    private bool $connected = false;
    private ?string $lastSelectErrorMessage = null;
    private int $heartbeatInterval = 0;
    private float $lastReadAt = 0.0;
    private float $lastWriteAt = 0.0;
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
        if (!$this->connected || $this->stream === null || !is_resource($this->stream)) {
            if ($this->connected) {
                $this->connected = false;
                $this->notifyConnectionLost('Connection stream is not available');
            }
            throw new ConnectionException('Cannot use socket: socket is not connected');
        }

        return $this->stream;
    }
    private int $correlationId = 0;
    /**
     * Correlated responses read by request() while it was waiting for a different
     * correlation ID (e.g. a nested request() issued from a server-push handler
     * such as a ConsumerUpdate query). Consumed only by the matching
     * correlation ID in request().
     *
     * @var list<array{correlationId: int, result: object|ProtocolException}>
     */
    private array $pendingResponses = [];
    private bool $running = false;
    private bool $stopRequested = false;
    private readonly bool $debugLogging;

    /** @var array<int, array{onConfirm: callable, onError: callable}> */
    private array $publisherCallbacks = [];
    /** @var array<int, callable(string): void> */
    private array $connectionLostHandlers = [];
    /** @var array<int, callable> */
    private array $subscriberCallbacks = [];
    /** @var array<int, callable> */
    private array $creditErrorHandlers = [];
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
        0x8009 => true, // CreditResponse (error-only, no correlation id)
        0x0010 => true, // MetadataUpdate
        0x0016 => true, // Close (server-initiated)
        0x0017 => true, // Heartbeat
        0x001a => true, // ConsumerUpdate
    ];

    public const DEFAULT_MAX_FRAME_SIZE = 8 * 1024 * 1024; // 8MB safety limit

    /**
     * How many consecutive signal interruptions (EINTR) readLoop() tolerates
     * before it gives up and raises ConnectionException again (#602).
     *
     * readLoop() is the one select site with no wall-clock bound of its own:
     * readLoop(null, null) runs until stop(), a disconnect or a real failure, so
     * a retry there is bounded by nothing but the predicate being right. An
     * unbounded retry would be a run that never returns and never marks the
     * connection dead — strictly worse than the ConnectionException callers
     * already handle, and the CPU cost climbs with the rate driving it (see the
     * measurements below). The counter resets on any select that returns (ready
     * or timed out), so only an unbroken run of interruptions can trip it.
     *
     * WHY THIS NUMBER, AND HOW TO READ IT
     *
     * Trip time is exactly this constant divided by the signal rate — for any
     * source fast enough that each new signal preempts the select before it can
     * return (roughly anything above 1/s, since the loop caps each select at 1s).
     * Below that rate the select times out, the counter resets, and the cap is
     * unreachable. Verified with a fork()ed SIGUSR1 sender on an idle socket;
     * trip times matched constant/rate to three significant figures at every
     * rate tried.
     *
     * The value is deliberately far above any rate a program produces on
     * purpose, because the cap punishes a *healthy* connection with the
     * reconnect-triggering exception — the very failure #602 exists to remove.
     * At 1,000,000, measured on an idle socket with a fork()ed SIGUSR1 sender:
     *
     *   ~141,000 signals/s (a pegged busy loop) -> ConnectionException after 7.1s
     *   ~13,800 signals/s (50us gap)           -> after 72.6s
     *   ~7,400 signals/s  (100us gap)          -> after 135.3s
     *
     * and extrapolating the same constant/rate relation: a 1ms tick (~875/s)
     * needs ~19 minutes, and it takes ~16,700 signals/s sustained for a full
     * minute to reach the cap at all. So nothing a real watchdog, supervisor or
     * shutdown handler does can reach it, while a genuinely unbounded run still
     * fails loudly in seconds.
     *
     * The cost is bounded at every one of those rates, and it is worth stating
     * with the rate attached, because "cheap" is only true relative to a
     * particular rate. Each retry blocks in select(2) until the next signal
     * rather than spinning, so measured on an idle socket it costs 0.4% of a
     * core at ~875 signals/s, 2.7% at ~14,900/s and 15.8% at a pegged
     * ~136,000/s storm (1.163s of CPU over 7.370s) — the last being the case
     * this bound exists to end. A stalled select that returned instantly on a
     * stream of spurious readiness, rather than a signal storm, is the only
     * shape that would approach a full core, and that is not reachable from a
     * select that blocks.
     *
     * This is a runaway guard, not a signal-rate policy. It is deliberately not
     * covered by a test: pinning it needs 10^6 real consecutive EINTRs, which
     * in turn needs an FFI harness that closes a socket's fd to force the
     * predicate to lie — neither is portable enough for the unit suite.
     */
    private const MAX_CONSECUTIVE_SELECT_INTERRUPTIONS = 1000000;

    /**
     * Maximum number of publishing ids embedded in a PSR-3 warning context.
     *
     * A late PublishConfirm/PublishError can carry roughly 1,000,000 ids within
     * DEFAULT_MAX_FRAME_SIZE, so the complete list must never reach the logger:
     * a single record would scale with the frame and can spike log processors
     * or in-memory handlers. The warning logs the total count plus this bounded
     * prefix, which is enough to diagnose a dropped frame (#522).
     *
     * Producer::drainPendingConfirms() reuses this bound so both log sites stay
     * consistent.
     */
    public const MAX_LOGGED_PUBLISHING_IDS = 10;

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
     * Default per-I/O-call timeout, enforced by the stream_select() deadline in
     * connect()/readBytes()/writeAll().
     *
     * Without it every read or write waits forever, so a peer that stops
     * mid-frame hangs the client and every documented timeout
     * (Consumer::read(), readFrame(), readLoop()) silently becomes infinite
     * (GitHub #402). This bounds a single I/O call, not a whole operation:
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

    private float $socketTimeout;
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
     * Per-command version ranges the broker reported in the
     * ExchangeCommandVersions handshake, keyed by protocol command key.
     *
     * Empty until {@see setCommandVersions()} is called (and left empty when
     * the handshake is unavailable), in which case every command is treated as
     * version 1 only — see {@see supportsCommandVersion()}.
     *
     * @var array<int, CommandVersion>
     */
    private array $commandVersions = [];

    /**
     * Hard upper bound on {@see $abandonedCorrelationIds}.
     *
     * The set is only pruned when a matching late reply finally arrives, and
     * correlation ids are monotonic (never reused), so without a bound a caller
     * that abandons requests could grow it without limit. In practice only the
     * single ExchangeCommandVersions handshake can abandon an id — and only on a
     * read-side timeout — so this cap is never reached in normal use; it exists
     * so the state cannot grow without bound. Oldest-first eviction is safe
     * because the oldest id is the least likely to still have a reply in flight.
     */
    private const MAX_ABANDONED_CORRELATION_IDS = 64;

    /**
     * Correlation ids of requests that already timed out and whose reply, if it
     * ever arrives, must be discarded rather than handed to another caller.
     * Filled by {@see abandonCorrelation()}, consumed by {@see readResponse()}
     * and cleared by {@see close()}.
     *
     * @var array<int, true>
     */
    private array $abandonedCorrelationIds = [];

    /**
     * @param string                $host     RabbitMQ stream server hostname
     * @param int                   $port     RabbitMQ stream server port
     * @param LoggerInterface       $logger   PSR-3 logger (defaults to NullLogger)
     * @param BinarySerializerInterface $serializer Serializer for request/response frames
     * @param float                 $socketTimeout Per-I/O-call timeout in seconds,
     *                                        enforced by a stream_select() deadline;
     *                                        must be > 0
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
        // Resolve once at construction so the frame metadata path is skipped
        // entirely when the logger won't emit debug records (NullLogger default).
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
        // socket timeout options (GitHub #402). stream_set_timeout()
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
        $this->lastReadAt = microtime(true);
        $this->lastWriteAt = $this->lastReadAt;
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
            [$selectTimeoutSec, $selectTimeoutUsec] = $this->splitSelectTimeout($remaining);
            $this->selectStreams($read, $write, $except, $selectTimeoutSec, $selectTimeoutUsec);
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
     * retained the old recv()/send() EINTR behaviour on the select-based path;
     * #602 extended it to readLoop(), readFrame() and the sendFrame() write-wait).
     *
     * PHP's stream API does not expose errno for a failed stream_select().
     * selectStreams() therefore captures the warning message generated by that
     * exact call and delegates to the application's existing error handler. This
     * avoids the process-global, sticky error_get_last() slot: an earlier warning
     * cannot be mistaken for this failure, and a swallowing application handler
     * cannot hide the warning from this class. This relies on PHP emitting a
     * warning for stream_select() syscall failures; argument/type errors are
     * exceptions and propagate normally.
     *
     * The message match assumes strerror(EINTR) is "Interrupted system call".
     * glibc deliberately excludes errno strings from gettext, so this holds under
     * any LC_MESSAGES on glibc and on macOS; a libc that localises errno strings
     * would need a numeric reason instead.
     *
     * Callers must keep retries bounded independently: readLoop() caps
     * consecutive interruptions (see self::MAX_CONSECUTIVE_SELECT_INTERRUPTIONS),
     * the other four sites are bounded by their own deadline.
     */
    private function selectWasInterrupted(): bool
    {
        return $this->lastSelectErrorMessage !== null
            && stripos($this->lastSelectErrorMessage, 'interrupted system call') !== false;
    }

    /**
     * Run stream_select() while capturing its warning without bypassing the
     * application's error handler. The temporary handler records the warning
     * before delegating; if the application handler swallows it, the result is
     * still available to selectWasInterrupted(). Restoring in finally preserves
     * the caller's handler even if the select or delegated handler throws.
     *
     * @param resource[]|null $read
     * @param resource[]|null $write
     * @param resource[]|null $except
     */
    private function selectStreams(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        int $timeoutSec,
        int $timeoutUsec
    ): int|false {
        $this->lastSelectErrorMessage = null;
        $selectErrorMessage = null;
        /** @var callable|null $previousHandler */
        $previousHandler = null;
        $captureHandler = function (
            int $severity,
            string $message,
            string $file,
            int $line
        ) use (
            &$previousHandler,
            &$selectErrorMessage
        ): bool {
            if ($severity === E_WARNING && str_starts_with($message, 'stream_select():')) {
                $selectErrorMessage = $message;
            }

            return $this->delegateSelectWarning($previousHandler, $severity, $message, $file, $line);
        };
        $previousHandler = set_error_handler($captureHandler);

        try {
            return @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
        } finally {
            restore_error_handler();
            $this->lastSelectErrorMessage = $selectErrorMessage;
        }
    }

    /**
     * Delegate a captured select warning to the handler that was installed by the caller.
     *
     * @param callable|null $handler
     */
    private function delegateSelectWarning(
        mixed $handler,
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!is_callable($handler)) {
            return false;
        }

        return (bool) $handler($severity, $message, $file, $line);
    }

    /**
     * Split a timeout in seconds into the (tv_sec, tv_usec) pair that
     * stream_select()/select(2) expects.
     *
     * The microseconds are always the true fractional part of $seconds, so the
     * invariant `0 <= tv_usec < 1_000_000` holds for every non-negative input.
     * select(2) rejects `tv_usec >= 1_000_000` with EINVAL, which is precisely
     * how #382 happened: one call site clamped the seconds with min(..., 1)
     * while deriving the microseconds from the *unclamped* remainder
     * (2.5s -> sec = 1, usec = 1_500_000). Routing every site through this
     * helper makes the two halves impossible to desynchronise.
     *
     * A caller that wants a shorter wait than $seconds (readLoop() polls at
     * most once per second so stop()/deadline checks stay responsive) clamps
     * the argument *before* calling this — never one half on its own.
     *
     * The argument must be non-negative; every current call site guards its
     * remaining budget or derives it from an already-checked deadline.
     *
     * @param float $seconds Non-negative timeout in seconds
     * @return array{int, int} [tv_sec, tv_usec] with 0 <= tv_usec < 1_000_000
     */
    private function splitSelectTimeout(float $seconds): array
    {
        $sec = (int) $seconds;
        $usec = (int) (($seconds - $sec) * 1_000_000);

        return [$sec, $usec];
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
     * Set the negotiated heartbeat interval. A value of zero disables heartbeat
     * sending and missed-heartbeat detection.
     *
     * Heartbeats are maintained while the application is inside a library I/O
     * call; time spent outside the library cannot be monitored by this client.
     */
    public function setHeartbeatInterval(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('heartbeatInterval must not be negative');
        }

        $this->heartbeatInterval = $seconds;
        $this->lastReadAt = microtime(true);
        $this->lastWriteAt = $this->lastReadAt;
    }

    public function getHeartbeatInterval(): int
    {
        return $this->heartbeatInterval;
    }

    /**
     * Close the TCP socket connection.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function close(): void
    {
        $wasConnected = $this->connected;
        if ($this->stream !== null && is_resource($this->stream)) {
            try {
                fclose($this->stream);
            } catch (\Throwable) {
                // Stream may already be closed, ignore
            }
        }
        $this->stream = null;
        $this->connected = false;
        if ($wasConnected) {
            $this->notifyConnectionLost('Connection was closed');
        }
        // A closed connection cannot read a late reply, so the abandoned-id set
        // has no further use and must not be carried by a reused instance (R2-3).
        $this->abandonedCorrelationIds = [];
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
     * Unlike the previous socket-based implementation, only resource validity is
     * checked: PHP streams have no equivalent of a sticky socket-error probe,
     * so no fatal error state can be detected here. A dead peer is
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
            $this->notifyConnectionLost('Connection stream is no longer available');
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
     * Record the per-command version ranges the broker reported.
     *
     * Called by the high-level {@see \CrazyGoat\RabbitStream\Client\Connection}
     * after the ExchangeCommandVersions handshake succeeds; keyed by protocol
     * command key (see {@see \CrazyGoat\RabbitStream\VO\CommandVersion::getKey()}).
     * Passing an empty array is the "nothing negotiated" state, which
     * {@see supportsCommandVersion()} reads as version 1 for every command.
     *
     * @param array<int, CommandVersion> $commandVersions Supported ranges keyed by protocol command key
     */
    public function setCommandVersions(array $commandVersions): void
    {
        $this->commandVersions = $commandVersions;
    }

    /**
     * The per-command version ranges the broker reported.
     *
     * @return array<int, CommandVersion> Supported ranges keyed by protocol command key
     */
    public function getCommandVersions(): array
    {
        return $this->commandVersions;
    }

    /**
     * Whether the broker reported support for a given version of a command.
     *
     * The version 1 baseline is assumed whenever a command was not negotiated
     * — either because the broker never answered ExchangeCommandVersions, or
     * simply did not list this command. This is what lets callers fall back to
     * v1 on a broker that does not implement version negotiation.
     *
     * @param KeyEnum $key Command to check.
     * @param int $version Version to check (1-based).
     * @return bool True when the reported range includes $version.
     */
    public function supportsCommandVersion(KeyEnum $key, int $version): bool
    {
        $range = $this->commandVersions[$key->value] ?? null;
        if ($range === null) {
            return $version === 1;
        }

        return $version >= $range->getMinVersion() && $version <= $range->getMaxVersion();
    }

    /**
     * Mark a request's correlation id as abandoned.
     *
     * A caller that times out waiting for a correlated reply can leave that
     * reply in flight; when it eventually arrives it would otherwise be handed
     * to the next `readMessage()`/`request()` as if it belonged to that call,
     * desynchronising the stream. Recording the id here makes
     * {@see readResponse()} discard the late frame instead (GitHub #381).
     *
     * This is an internal wiring seam for the handshake; callers outside the
     * client layer have no reason to use it.
     *
     * The set is bounded by {@see MAX_ABANDONED_CORRELATION_IDS}: once full, the
     * oldest id is evicted so a caller that abandons many requests cannot grow
     * the state without limit (R2-3). It is also cleared by {@see close()}.
     *
     * @param int $correlationId Correlation id of the timed-out request
     */
    public function abandonCorrelation(int $correlationId): void
    {
        if (count($this->abandonedCorrelationIds) >= self::MAX_ABANDONED_CORRELATION_IDS) {
            // The set is non-empty whenever the cap is reached, so the first
            // key is always an int here.
            $oldest = array_key_first($this->abandonedCorrelationIds);
            unset($this->abandonedCorrelationIds[$oldest]);
        }

        $this->abandonedCorrelationIds[$correlationId] = true;
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
        unset($this->creditErrorHandlers[$subscriptionId]);
        unset($this->consumerUpdateHandlers[$subscriptionId]);
    }

    /**
     * Register a handler for a rejected Credit request on a subscription.
     *
     * @param int $subscriptionId Subscription ID as declared with the server
     * @param callable $handler Called with (CreditResponseV1 $response)
     */
    public function registerCreditErrorHandler(int $subscriptionId, callable $handler): void
    {
        $this->creditErrorHandlers[$subscriptionId] = $handler;
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
     *
     * If the handler throws or returns an invalid value, StreamConnection logs the
     * failure, sends a "none" reply for the query, then rethrows to the readLoop() caller.
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
     * Register a handler notified when this connection can no longer carry frames.
     *
     * @param int $handlerId Unique handler id, typically a publisher id
     * @param callable(string): void $handler Receives the reason the connection was lost
     */
    public function registerConnectionLostHandler(int $handlerId, callable $handler): void
    {
        $this->connectionLostHandlers[$handlerId] = $handler;
    }

    /** Remove a connection-loss handler registered for a producer. */
    public function unregisterConnectionLostHandler(int $handlerId): void
    {
        unset($this->connectionLostHandlers[$handlerId]);
    }

    /** Notify every active producer once, before discarding the callbacks. */
    private function notifyConnectionLost(string $reason): void
    {
        if ($this->connectionLostHandlers === []) {
            return;
        }

        $handlers = $this->connectionLostHandlers;
        $this->connectionLostHandlers = [];
        foreach ($handlers as $handler) {
            $handler($reason);
        }
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
     *                           [int $offsetType, int $offset] for the reply. The offset must
     *                           be 0 for the value-less none/first/last/next types.
     *                           A return value that is not a two-element list of ints is
     *                           rejected with an InvalidArgumentException when the frame is
     *                           dispatched, not silently coerced.
     *
     * If the callback throws or returns an invalid reply, StreamConnection logs the
     * failure, sends a "none" reply for the query, then rethrows to the readLoop() caller.
     */
    public function onConsumerUpdate(callable $callback): void
    {
        $this->consumerUpdateCallback = \Closure::fromCallable($callback);
    }

    /**
     * Signal an active read to stop gracefully at its next poll boundary.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->stopRequested = true;
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

            // The timeout is a budget for the whole wait, not for one select(2):
            // a select interrupted by a signal (EINTR, GitHub #602) is retried
            // with whatever is left of it, so the caller sees the TimeoutException
            // it asked for instead of a ConnectionException it would reconnect on.
            while (true) {
                $read = null;
                $write = [$stream];
                $except = null;

                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new TimeoutException("Write timeout: connection not ready for writing");
                }

                [$timeoutSec, $timeoutUsec] = $this->splitSelectTimeout($remaining);

                $ready = $this->selectStreams($read, $write, $except, $timeoutSec, $timeoutUsec);

                if ($ready === false) {
                    if (!$this->selectWasInterrupted()) {
                        $this->connected = false;
                        $this->notifyConnectionLost('stream_select failed while waiting for write readiness');
                        throw new ConnectionException("stream_select failed while waiting for write readiness");
                    }

                    continue;
                }

                if ($ready === 0) {
                    throw new TimeoutException("Write timeout: connection not ready for writing");
                }

                break;
            }
        }

        $written = $this->writeAll($frame);
        $this->lastWriteAt = microtime(true);

        return $written;
    }

    /**
     * Write every byte of $frame, looping over partial writes.
     *
     * fwrite() may accept fewer bytes than requested (send-buffer pressure, a
     * large batch Publish frame, a signal). Sending the rest is not optional:
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
     * @throws TimeoutException    If nothing at all could be written before the $socketTimeout deadline expired
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

            [$timeoutSec, $timeoutUsec] = $this->splitSelectTimeout($remaining);

            $ready = $this->selectStreams($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($ready === false) {
                if ($this->selectWasInterrupted()) {
                    continue;
                }
                $this->connected = false;
                $this->notifyConnectionLost('stream_select failed while writing');
                throw new ConnectionException('stream_select failed while writing');
            }
            if ($ready === 0) {
                $this->writeTimeout($sent, $total);
            }

            // select() said writable, so on a non-blocking stream fwrite() will
            // accept at least one byte and never block.
            $written = @fwrite($stream, $sent === 0 ? $frame : substr($frame, $sent));

            if ($written === false || $written === 0) {
                $this->close();
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
     * transparently to registered callbacks before returning. This uncorrelated read
     * is intended for handshake frames that have no correlation ID; use request() for
     * correlated exchanges so replies cannot be attributed to the wrong caller. It
     * never returns responses parked for another request.
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
        return $this->readResponse($timeout, null);
    }

    /**
     * Send a correlated request and return its matching response.
     *
     * This matches the reply by correlation ID, so it is safe to call
     * re-entrantly from a server-push handler (for example a ConsumerUpdate
     * handler querying the stored offset while an outer request() is still
     * waiting for its own SubscribeResponse). Responses that belong to another
     * in-flight request are parked for the matching request.
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

        try {
            return $this->readResponse($timeout, $request->getCorrelationId());
        } catch (TimeoutException $exception) {
            // sendMessage() runs outside this try, so a write timeout is not
            // abandoned: the request did not reach the broker. A read timeout
            // can still leave a reply in flight, which must be discarded.
            $this->abandonCorrelation($request->getCorrelationId());

            throw $exception;
        }
    }

    private function maintainHeartbeat(): void
    {
        if ($this->heartbeatInterval === 0 || !$this->connected) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastReadAt >= 2 * $this->heartbeatInterval) {
            $this->close();
            throw new ConnectionException(sprintf(
                'Connection closed after missing inbound frames for %.1f seconds (heartbeat interval: %d seconds)',
                $now - $this->lastReadAt,
                $this->heartbeatInterval
            ));
        }

        if ($now - $this->lastWriteAt >= $this->heartbeatInterval) {
            // HeartbeatRequestV1 is deliberately sent as a raw frame: sendMessage()
            // assigns correlation IDs to correlated request objects, and protocol
            // heartbeats have no correlation ID.
            $content = $this->serializer->serialize(new HeartbeatRequestV1());
            $this->sendFrame($this->wrapFrame($content));
        }
    }

    private function heartbeatWaitTimeout(float $timeout): float
    {
        if ($this->heartbeatInterval === 0) {
            return $timeout;
        }

        $untilWrite = $this->lastWriteAt + $this->heartbeatInterval - microtime(true);
        $untilDead = $this->lastReadAt + 2 * $this->heartbeatInterval - microtime(true);

        return min($timeout, max(0.0, min($untilWrite, $untilDead)));
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
                    return $this->unwrapPendingResponse($parked['result']);
                }
            }

            $remainingTimeout = $timeout;
            if ($deadline !== null) {
                $remainingTimeout = $deadline - microtime(true);
                if ($remainingTimeout <= 0) {
                    throw new TimeoutException("Read timeout");
                }
            }

            $frame = $this->readFrame($this->heartbeatWaitTimeout($remainingTimeout));
            if (!$frame instanceof \CrazyGoat\RabbitStream\Buffer\ReadBuffer) {
                $this->maintainHeartbeat();
                if ($timeout <= 0 || ($deadline !== null && microtime(true) >= $deadline)) {
                    throw new TimeoutException("Read timeout");
                }
                continue;
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

            $payload = $frame->getRemainingBytes();
            $correlationId = $this->readCorrelationId($key, $payload);
            try {
                $result = $this->serializer->deserialize($payload);
            } catch (ProtocolException $exception) {
                if ($correlationId === null) {
                    throw $exception;
                }
                $result = $exception;
            }

            if ($correlationId !== null && isset($this->abandonedCorrelationIds[$correlationId])) {
                // Reply to a request that already timed out (see
                // abandonCorrelation()): dropping it keeps the next caller from
                // misattributing it as their own response, including error replies.
                unset($this->abandonedCorrelationIds[$correlationId]);
                $this->logger->warning(
                    'Discarding a late reply for a request that already timed out',
                    [
                        'correlationId' => $correlationId,
                        'response' => $result instanceof ProtocolException ? 'error' : $result::class,
                    ]
                );
                continue;
            }

            if ($expectedCorrelationId === null) {
                return $this->unwrapPendingResponse($result);
            }

            if ($correlationId === null) {
                // A response frame without a correlation ID (in practice a Credit
                // error, which the broker only sends for a rejected Credit request,
                // e.g. after a single-active-consumer handover) cannot be the reply
                // we are waiting for. Log and keep reading.
                $this->logger->warning('Unsolicited response received while awaiting correlated reply', [
                    'response' => $result instanceof ProtocolException ? 'error' : $result::class,
                    'details' => $result instanceof CreditResponseV1
                        ? [
                            'subscriptionId' => $result->getSubscriptionId(),
                            'responseCode' => $result->getResponseCode(),
                        ]
                        : [],
                ]);
                continue;
            }

            if ($correlationId !== $expectedCorrelationId) {
                // Belongs to another in-flight request (outer or nested) — park it.
                $this->pendingResponses[] = ['correlationId' => $correlationId, 'result' => $result];
                continue;
            }

            return $this->unwrapPendingResponse($result);
        }
    }

    /**
     * Read the correlation id from a correlated response frame without consuming
     * the payload used by the configured serializer.
     *
     * @param string $payload Frame payload beginning with key and version.
     */
    private function readCorrelationId(int $key, string $payload): ?int
    {
        // Credit errors and server-push frames have no correlation ID. Those are
        // handled separately, but the key guard also protects custom serializers.
        if ($key === KeyEnum::CREDIT_RESPONSE->value || strlen($payload) < 8) {
            return null;
        }

        $correlation = unpack('N', $payload, 4);
        return $correlation === false ? null : $correlation[1];
    }

    /**
     * Return a parked response or throw the protocol error it represents.
     */
    private function unwrapPendingResponse(object $result): object
    {
        if ($result instanceof ProtocolException) {
            throw $result;
        }
        return $result;
    }

    /**
     * @return array{correlationId: int, result: object|ProtocolException}|null
     */
    private function takePendingResponse(int $correlationId): ?array
    {
        foreach ($this->pendingResponses as $index => $pending) {
            if ($pending['correlationId'] === $correlationId) {
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
     * A `stream_select()` interrupted by a signal (EINTR, #602) is retried
     * rather than treated as a failure. The retry is bounded: a select that
     * returns at all resets the count, and an unbroken run of more than 1,000,000
     * interruptions in a row raises a `ConnectionException` naming the count
     * ("stream_select failed in readLoop: 1000001 consecutive signal
     * interruptions") — the same class this method has always raised here, with
     * a message that says the wait was abandoned rather than that the socket
     * failed. That bound matters because this is the only select site that can
     * run without a `$timeout`, so it has no deadline to stop a retry; see
     * {@see selectWasInterrupted()} for why the bound is needed at all.
     *
     * @param int|null   $maxFrames Maximum number of frames to process (null = unlimited)
     * @param float|null $timeout   Maximum wall-clock time in seconds (null = unlimited)
     * @return int Number of frames processed (dispatched server-push frames plus any
     *             discarded non-server-push frames); 0 means the loop ended
     *             on timeout, stop() or disconnect without handling any frame
     * @throws ConnectionException If the socket is not connected, if a select fails
     *                             and is not a signal interruption, or if signal
     *                             interruptions outrun the retry bound above
     */
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int
    {
        $stream = $this->requireStream();

        $this->running = true;
        $dispatched = 0;
        $interruptions = 0;
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while ($this->running && $this->connected) {
            $this->maintainHeartbeat();

            // Check if timeout has expired
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $read = [$stream];
            $write = null;
            $except = null;

            // Calculate remaining timeout for stream_select.
            // Cap $remaining BEFORE the split and hand the capped value to the
            // helper: select(2) rejects tv_usec >= 1_000_000 with EINVAL (e.g.
            // 2.5s would produce sec = 1, usec = 1_500_000 without the cap), and
            // polling at most once per second keeps stop()/deadline checks
            // responsive.
            $selectTimeout = 1.0;
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $selectTimeout = min($selectTimeout, $remaining);
            }
            $selectTimeout = $this->heartbeatWaitTimeout($selectTimeout);
            [$selectTimeoutSec, $selectTimeoutUsec] = $this->splitSelectTimeout($selectTimeout);

            $ready = $this->selectStreams($read, $write, $except, $selectTimeoutSec, $selectTimeoutUsec);

            if ($ready === false) {
                // A signal that lands inside select(2) fails it with EINTR, not
                // with a broken socket (GitHub #602). Retry as a spurious
                // wakeup: the loop head re-checks running/connected and
                // recomputes the remaining budget, so a handler that calls
                // stop() ends the loop cleanly and a deadline still holds.
                if ($this->selectWasInterrupted()) {
                    $interruptions++;

                    // readLoop(null, null) has no deadline, so nothing else stops
                    // a retry here. Bound the unbroken run explicitly and fail
                    // loudly rather than spin: a misfiring predicate would
                    // otherwise burn a core forever without marking the
                    // connection dead.
                    if ($interruptions > self::MAX_CONSECUTIVE_SELECT_INTERRUPTIONS) {
                        throw new ConnectionException(sprintf(
                            'stream_select failed in readLoop: %d consecutive signal interruptions',
                            $interruptions
                        ));
                    }

                    continue;
                }
                $this->connected = false;
                $this->notifyConnectionLost('stream_select failed in readLoop');
                throw new ConnectionException('stream_select failed in readLoop');
            }

            // The select itself completed (with data or on timeout), so any
            // earlier run of interruptions was a transient signal burst.
            $interruptions = 0;

            if ($ready === 0) {
                $this->maintainHeartbeat();
                continue;
            }

            // stream_select() already confirmed the stream is readable above;
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
            KeyEnum::CREDIT_RESPONSE->value => $this->handleCreditResponse($frame),
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
            return;
        }
        // Intentional tombstone guard: the publisher id was unregistered (e.g.
        // Producer::close() released it) or was never declared on this
        // connection. The frame is not dispatched, but it must not vanish
        // silently — every confirm on it is a lost confirm (GitHub #522).
        $publishingIds = $confirm->getPublishingIds();
        $count = count($publishingIds);
        $this->logger->warning(
            'Dropping PublishConfirm frame for unregistered publisher id'
            . $this->publishingIdTruncationNote($count),
            [
                'publisherId' => $publisherId,
                'frame' => 'PublishConfirm',
                'publishingIdCount' => $count,
                'publishingIds' => array_slice($publishingIds, 0, self::MAX_LOGGED_PUBLISHING_IDS),
            ]
        );
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
            return;
        }
        // See handlePublishConfirm(): intentional tombstone guard, logged so a
        // dropped error frame is visible instead of silent (GitHub #522).
        $errors = $error->getErrors();
        $count = count($errors);
        $this->logger->warning(
            'Dropping PublishError frame for unregistered publisher id'
            . $this->publishingIdTruncationNote($count),
            [
                'publisherId' => $publisherId,
                'frame' => 'PublishError',
                'publishingIdCount' => $count,
                'publishingIds' => array_map(
                    static fn (PublishingError $e): int => $e->getPublishingId(),
                    array_slice($errors, 0, self::MAX_LOGGED_PUBLISHING_IDS)
                ),
            ]
        );
    }

    /**
     * Human-readable suffix for a dropped-frame warning: empty while every id
     * fits in the context, otherwise states how many were left out (#522).
     */
    private function publishingIdTruncationNote(int $count): string
    {
        if ($count <= self::MAX_LOGGED_PUBLISHING_IDS) {
            return '';
        }

        return sprintf(
            ' (%d publishing ids in total; only the first %d are logged)',
            $count,
            self::MAX_LOGGED_PUBLISHING_IDS
        );
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

    private function handleCreditResponse(ReadBuffer $frame): void
    {
        $response = CreditResponseV1::fromStreamBuffer($frame);
        if (!$response instanceof CreditResponseV1) {
            throw new DeserializationException('Failed to deserialize CreditResponse frame');
        }

        $subscriptionId = $response->getSubscriptionId();
        $context = [
            'subscriptionId' => $subscriptionId,
            'responseCode' => sprintf('0x%04x', $response->getResponseCode()),
        ];
        if (isset($this->creditErrorHandlers[$subscriptionId])) {
            $this->logger->warning('Credit request rejected by server', $context);
            ($this->creditErrorHandlers[$subscriptionId])($response);
            return;
        }

        $this->logger->warning('Credit request rejected for unregistered subscription', $context);
    }

    private function handleServerClose(ReadBuffer $frame): void
    {
        $frame->getUint16(); // key
        $frame->getUint16(); // version
        $correlationId = $frame->getUint32();
        $closingCode = $frame->getUint16();
        $closingReason = $frame->getString();
        if ($this->debugLogging) {
            $this->logger->debug(sprintf(
                'Server-initiated close: code=%d, reason=%s',
                $closingCode,
                $closingReason ?? ''
            ));
        }

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

        try {
            $offsetType = OffsetSpec::TYPE_NONE;
            $offset = 0;

            $subscriptionHandler = $this->consumerUpdateHandlers[$query->getSubscriptionId()] ?? null;
            if ($subscriptionHandler !== null) {
                $offsetSpec = $subscriptionHandler($query);
                if ($offsetSpec !== null && !$offsetSpec instanceof OffsetSpec) {
                    throw new InvalidArgumentException(
                        'The per-subscription ConsumerUpdate handler must return OffsetSpec|null, got '
                        . get_debug_type($offsetSpec)
                    );
                }
                if ($offsetSpec instanceof OffsetSpec) {
                    [$offsetType, $offset] = [$offsetSpec->getType(), $offsetSpec->getValue() ?? 0];
                }
            } elseif ($this->consumerUpdateCallback instanceof \Closure) {
                [$offsetType, $offset] = $this->resolveGlobalConsumerUpdateReply(
                    ($this->consumerUpdateCallback)($query)
                );
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
        } catch (Throwable $exception) {
            $this->logger->error(
                'ConsumerUpdate handler failed; sending a none reply before rethrowing',
                [
                    'correlationId' => $query->getCorrelationId(),
                    'subscriptionId' => $query->getSubscriptionId(),
                    'exception' => $exception,
                ]
            );

            $reply = new ConsumerUpdateReplyV1(
                responseCode: 0x0001,
                offsetType: OffsetSpec::TYPE_NONE,
                offset: 0,
            );
            $reply->withCorrelationId($query->getCorrelationId());
            $content = $this->serializer->serialize($reply);
            $this->sendFrame($this->wrapFrame($content));

            throw $exception;
        }

        $reply->withCorrelationId($query->getCorrelationId());
        $content = $this->serializer->serialize($reply);
        $this->sendFrame($this->wrapFrame($content));
    }

    /**
     * Validate the `[int $offsetType, int $offset]` a global onConsumerUpdate()
     * callback returned before it is destructured.
     *
     * The callback is typed only as `callable`, so nothing stops it from
     * returning `[]`, a one-element list, string keys or non-int values. PHP
     * would then raise an "Undefined array key" warning (or a "Cannot use int as
     * array" one) and hand `null` to the constructor, which fails with a raw
     * `TypeError` (GitHub #529). A malformed return is a caller error, so it is
     * reported here as an `InvalidArgumentException` naming the offending value.
     *
     * @param mixed $reply Return value of the global onConsumerUpdate() callback.
     * @return array{0: int, 1: int} The validated offset type and offset.
     * @throws InvalidArgumentException If the callback did not return a two-element
     *                                  list of ints.
     */
    private function resolveGlobalConsumerUpdateReply(mixed $reply): array
    {
        if (
            !is_array($reply)
            || !array_key_exists(0, $reply)
            || !array_key_exists(1, $reply)
            || !is_int($reply[0])
            || !is_int($reply[1])
        ) {
            throw new InvalidArgumentException(
                'The onConsumerUpdate() callback must return [int $offsetType, int $offset], got '
                . $this->describeConsumerUpdateReturn($reply)
            );
        }

        return [$reply[0], $reply[1]];
    }

    /**
     * Describe a malformed onConsumerUpdate() callback return for the error
     * message. A bare `array` is useless to the caller, so an array is reported
     * by the number of elements it has, or by the types at the two positions the
     * reply is read from.
     *
     * @param mixed $reply Return value of the global onConsumerUpdate() callback.
     */
    private function describeConsumerUpdateReturn(mixed $reply): string
    {
        if (!is_array($reply)) {
            return get_debug_type($reply);
        }

        if (!array_key_exists(0, $reply) || !array_key_exists(1, $reply)) {
            return sprintf('array of %d element(s)', count($reply));
        }

        return sprintf('array{%s, %s}', get_debug_type($reply[0]), get_debug_type($reply[1]));
    }

    private function wrapFrame(string $content): string
    {
        // Direct pack()+concat instead of a WriteBuffer object: this runs once
        // per outgoing message (and once per heartbeat/close-response reply).
        return pack('N', strlen($content)) . $content;
    }

    /**
     * Consume a pending stop request, if one arrived during the current poll.
     *
     * @phpstan-impure
     */
    private function consumeStopRequest(): bool
    {
        if (!$this->stopRequested) {
            return false;
        }

        $this->stopRequested = false;

        return true;
    }

    /**
     * Read a single raw frame from the socket (length-prefixed).
     *
     * @param float $timeout Seconds to wait for data (0.0 = non-blocking poll); stop() is observed within one second
     * @return ReadBuffer|null Parsed frame buffer, or null if no data arrived within the timeout
     * @throws ConnectionException If the socket is not connected, frame exceeds max size, or read error occurs
     */
    public function readFrame(float $timeout = 30.0): ?ReadBuffer
    {
        $stream = $this->requireStream();
        $this->stopRequested = false;

        // The timeout is a budget for the whole call, not for one select(2):
        // waits are capped at one second so stop() is observed promptly, and a
        // select interrupted by a signal (EINTR, GitHub #602) is retried with
        // whatever is left of the budget.
        $deadline = microtime(true) + max(0.0, $timeout);
        $retried = false;

        while (true) {
            $remaining = $deadline - microtime(true);

            // The first select always runs, so a non-positive $timeout stays a
            // non-blocking poll. A retry only happens after an EINTR, and then
            // the exhausted budget means "nothing readable right now" — the
            // same answer a poll gives — so it returns null instead of spinning
            // on a zero-length select.
            if ($this->consumeStopRequest() || ($retried && $remaining <= 0)) {
                return null;
            }

            $read = [$stream];
            $write = null;
            $except = null;

            [$timeoutSec, $timeoutUsec] = $this->splitSelectTimeout(min(max(0.0, $remaining), 1.0));

            $ready = $this->selectStreams($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($ready === false) {
                if (!$this->selectWasInterrupted()) {
                    $this->connected = false;
                    $this->notifyConnectionLost('stream_select failed while waiting for frame data');
                    throw new ConnectionException('stream_select failed while waiting for frame data');
                }

                $retried = true;
                continue;
            }

            if ($this->consumeStopRequest()) {
                return null;
            }

            if ($ready === 0) {
                if (microtime(true) >= $deadline) {
                    return null;
                }

                continue;
            }

            return $this->readFrameNoWait();
        }
    }

    /**
     * Read a single raw frame from the stream without first calling stream_select().
     *
     * Callers must already know the stream is readable — this exists so readLoop(),
     * which already performs its own stream_select() before every frame, does not pay
     * for a second, redundant select per frame. Reads use non-blocking fread() calls.
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
        $this->lastReadAt = microtime(true);

        return new ReadBuffer($frameData);
    }

    /**
     * Log frame metadata only. Frame bodies can contain application data and
     * may be tens of megabytes, so they must never be copied into debug logs.
     * SASL_AUTHENTICATE frames retain an explicit redaction marker.
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

        $frameSize = strlen($frame) + ($keyOffset === 0 ? 4 : 0);
        if (strlen($frame) < $keyOffset + 2) {
            $this->logger->debug(sprintf('%s <unknown command, %d bytes>', $prefix, $frameSize));
            return;
        }

        $keyUnpacked = unpack('n', substr($frame, $keyOffset, 2));
        $key = $keyUnpacked !== false ? $keyUnpacked[1] : null;
        if ($key === KeyEnum::SASL_AUTHENTICATE->value) {
            $this->logger->debug(sprintf(
                '%s <redacted: SASL_AUTHENTICATE, %d bytes>',
                $prefix,
                $frameSize
            ));
            return;
        }

        $versionOffset = $keyOffset + 2;
        $versionUnpacked = strlen($frame) >= $versionOffset + 2
            ? unpack('n', substr($frame, $versionOffset, 2))
            : false;
        $version = $versionUnpacked !== false ? (string) $versionUnpacked[1] : 'unknown';
        $command = $key !== null ? KeyEnum::tryFrom($key)?->name : null;
        $command ??= sprintf('UNKNOWN_0x%04X', $key ?? 0);

        $this->logger->debug(sprintf(
            '%s %s v%s, %d bytes',
            rtrim($prefix),
            $command,
            $version,
            $frameSize
        ));
    }

    /**
     * Read exactly $length bytes from the connection.
     *
     * fread() returns whatever is currently available, so short reads are
     * accumulated in a loop, matching the semantics of the previous socket-based
     * implementation.
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

            // readTimeout() is the only way past the guard above with a
            // non-positive budget, and it never returns false (it reports an
            // empty frame-boundary read or throws). Clamp explicitly anyway so
            // splitSelectTimeout()'s non-negative precondition is enforced here
            // instead of depending on that non-local invariant.
            [$timeoutSec, $timeoutUsec] = $this->splitSelectTimeout(max(0.0, $remainingTime));

            $ready = $this->selectStreams($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($ready === false) {
                if ($this->selectWasInterrupted()) {
                    continue;
                }
                $this->connected = false;
                $this->notifyConnectionLost('stream_select failed while reading');
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
                    $this->close();
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
