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

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 5552,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly BinarySerializerInterface $serializer = new PhpBinarySerializer(),
    ) {
    }

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

    public function __destruct()
    {
        $this->close();
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function setMaxFrameSize(int $maxFrameSize): void
    {
        if ($maxFrameSize < 0) {
            throw new InvalidArgumentException(
                "Max frame size must be >= 0 (0 = no limit), got {$maxFrameSize}"
            );
        }
        $this->maxFrameSize = $maxFrameSize;
    }

    public function getMaxFrameSize(): int
    {
        return $this->maxFrameSize;
    }

    public function registerPublisher(int $publisherId, callable $onConfirm, callable $onError): void
    {
        $this->publisherCallbacks[$publisherId] = [
            'onConfirm' => $onConfirm,
            'onError' => $onError,
        ];
    }

    public function registerSubscriber(int $subscriptionId, callable $onDeliver): void
    {
        $this->subscriberCallbacks[$subscriptionId] = $onDeliver;
    }

    public function unregisterSubscriber(int $subscriptionId): void
    {
        unset($this->subscriberCallbacks[$subscriptionId]);
    }

    public function unregisterPublisher(int $publisherId): void
    {
        unset($this->publisherCallbacks[$publisherId]);
    }

    public function onMetadataUpdate(callable $callback): void
    {
        $this->metadataUpdateCallback = \Closure::fromCallable($callback);
    }

    public function onHeartbeat(?callable $callback = null): void
    {
        $this->heartbeatCallback = $callback !== null ? \Closure::fromCallable($callback) : null;
    }

    public function onConsumerUpdate(callable $callback): void
    {
        $this->consumerUpdateCallback = \Closure::fromCallable($callback);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function sendMessage(object $request, ?float $timeout = null): void
    {
        if ($request instanceof CorrelationInterface) {
            $this->correlationId++;
            $request->withCorrelationId($this->correlationId);
        }

        $content = $this->serializer->serialize($request);

        $frame = (new WriteBuffer())
            ->addUInt32(strlen($content))
            ->addRaw($content)
            ->getContents();

        $this->sendFrame($frame, $timeout);
    }

    public function sendFrame(string $frame, ?float $timeout = null): int
    {
        $this->logger->debug("Socket -> " . bin2hex($frame));

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

        if (!$this->socket instanceof \Socket) {
            throw new ConnectionException("Cannot write: socket is not connected");
        }

        $written = socket_write($this->socket, $frame, strlen($frame));
        if ($written === false) {
            throw new ConnectionException(
                "Failed to write to socket: " . socket_strerror(socket_last_error($this->socket))
            );
        }

        return $written;
    }

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

    public function readLoop(?int $maxFrames = null, ?float $timeout = null): void
    {
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

            // Calculate remaining timeout for socket_select
            $selectTimeoutSec = 1;
            $selectTimeoutUsec = 0;
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $selectTimeoutSec = (int) min($remaining, 1);
                $selectTimeoutUsec = (int) (($remaining - $selectTimeoutSec) * 1_000_000);
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

        switch ($key) {
            case KeyEnum::HEARTBEAT->value:
                HeartbeatRequestV1::fromStreamBuffer($frame);
                $heartbeat = new HeartbeatRequestV1();
                $content = $this->serializer->serialize($heartbeat);
                $this->sendFrame((new WriteBuffer())->addUInt32(strlen($content))->addRaw($content)->getContents());
                if ($this->heartbeatCallback instanceof \Closure) {
                    ($this->heartbeatCallback)();
                }
                break;

            case KeyEnum::PUBLISH_CONFIRM->value:
                $confirm = PublishConfirmResponseV1::fromStreamBuffer($frame);
                if (!$confirm instanceof PublishConfirmResponseV1) {
                    throw new DeserializationException('Failed to deserialize PublishConfirm frame');
                }
                $publisherId = $confirm->getPublisherId();
                if (isset($this->publisherCallbacks[$publisherId])) {
                    ($this->publisherCallbacks[$publisherId]['onConfirm'])($confirm->getPublishingIds());
                }
                break;

            case KeyEnum::PUBLISH_ERROR->value:
                $error = PublishErrorResponseV1::fromStreamBuffer($frame);
                if (!$error instanceof PublishErrorResponseV1) {
                    throw new DeserializationException('Failed to deserialize PublishError frame');
                }
                $publisherId = $error->getPublisherId();
                if (isset($this->publisherCallbacks[$publisherId])) {
                    ($this->publisherCallbacks[$publisherId]['onError'])($error->getErrors());
                }
                break;

            case KeyEnum::DELIVER->value:
                $deliver = DeliverResponseV1::fromStreamBuffer($frame);
                if (!$deliver instanceof DeliverResponseV1) {
                    throw new DeserializationException('Failed to deserialize Deliver frame');
                }
                $subscriptionId = $deliver->getSubscriptionId();
                if (isset($this->subscriberCallbacks[$subscriptionId])) {
                    ($this->subscriberCallbacks[$subscriptionId])($deliver);
                }
                break;

            case KeyEnum::CLOSE->value:
                // Server-initiated close: read the close request and send response
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
                // Send close response with OK
                $response = (new WriteBuffer())
                    ->addUInt16(KeyEnum::CLOSE_RESPONSE->value)
                    ->addUInt16(1) // version
                    ->addUInt32($correlationId)
                    ->addUInt16(0x0001); // responseCode OK
                $content = $response->getContents();
                $this->sendFrame(
                    (new WriteBuffer())->addUInt32(strlen($content))->addRaw($content)->getContents()
                );
                $this->close();
                break;

            case KeyEnum::METADATA_UPDATE->value:
                $update = MetadataUpdateResponseV1::fromStreamBuffer($frame);
                if ($this->metadataUpdateCallback instanceof \Closure) {
                    ($this->metadataUpdateCallback)($update);
                }
                break;

            case KeyEnum::CONSUMER_UPDATE->value:
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
                $this->sendFrame((new WriteBuffer())->addUInt32(strlen($content))->addRaw($content)->getContents());
                break;
        }
    }

    public function readFrame(float $timeout = 30.0): ?ReadBuffer
    {
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

        $this->logger->debug("Socket <-" . bin2hex($frameData));

        return new ReadBuffer($frameData);
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
            if ($chunk === false || $chunk === '') {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_ETIMEDOUT) {
                    return null;
                }
                throw new ConnectionException("Failed to read from socket: " . socket_strerror($error));
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}
