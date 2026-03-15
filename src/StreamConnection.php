<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Request\ConsumerUpdateReplyV1;
use CrazyGoat\StreamyCarrot\Request\HeartbeatRequestV1;
use CrazyGoat\StreamyCarrot\Response\ConsumerUpdateQueryV1;
use CrazyGoat\StreamyCarrot\Response\DeliverResponseV1;
use CrazyGoat\StreamyCarrot\Response\MetadataUpdateResponseV1;
use CrazyGoat\StreamyCarrot\Response\PublishConfirmResponseV1;
use CrazyGoat\StreamyCarrot\Response\PublishErrorResponseV1;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;

class StreamConnection
{
    private bool $connected = false;
    private ?\Socket $socket = null;
    private int $correlationId = 0;
    private bool $running = false;

    private array $publisherCallbacks = [];
    private array $subscriberCallbacks = [];
    private ?\Closure $metadataUpdateCallback = null;
    private ?\Closure $heartbeatCallback = null;
    private ?\Closure $consumerUpdateCallback = null;

    private const SERVER_PUSH_KEYS = [
        0x0003,
        0x0004,
        0x0008,
        0x0010,
        0x0017,
        0x001a,
    ];

    public function __construct(private string $host = '172.17.0.2', private int $port = 5552)
    {
    }

    public function connect(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \Exception("Cannot create socket: " . socket_strerror(socket_last_error()));
        }

        $result = socket_connect($socket, $this->host, $this->port);
        if (!$result) {
            throw new \Exception("Cannot connect to {$this->host}:{$this->port}: " . socket_strerror(socket_last_error($this->socket)));
        }

        $this->connected = true;
        $this->socket = $socket;
    }

    public function close(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
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

    public function sendMessage(object $request): void
    {
        $this->correlationId++;

        if ($request instanceof CorrelationInterface) {
            $request->withCorrelationId($this->correlationId);
        }

        if (!$request instanceof ToStreamBufferInterface) {
            throw new \Exception("Request must implement ToStreamBufferInterface");
        }
        $content = $request->toStreamBuffer()->getContents();

        $frame = (new WriteBuffer())
            ->addUInt32(strlen($content))
            ->addRaw($content)
            ->getContents();

        $this->sendFrame($frame);
    }

    public function sendFrame(string $frame): int
    {
        echo "Socket -> " . bin2hex($frame) . PHP_EOL;

        $written = socket_write($this->socket, $frame, strlen($frame));
        if ($written === false) {
            throw new \Exception("Failed to write to socket: " . socket_strerror(socket_last_error($this->socket)));
        }

        return $written;
    }

    public function readMessage(int $timeout = 30): object
    {
        while (true) {
            $frame = $this->readFrame($timeout);
            if ($frame === null) {
                throw new \Exception("Read timeout");
            }

            $key = $frame->peekUint16();

            if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
                $this->dispatchServerPush($frame);
                continue;
            }

            return ResponseBuilder::fromResponseBuffer($frame);
        }
    }

    public function readLoop(?int $maxFrames = null): void
    {
        $this->running = true;
        $dispatched = 0;

        while ($this->running) {
            $read = [$this->socket];
            $write = null;
            $except = null;

            $ready = socket_select($read, $write, $except, 1);

            if ($ready === false) {
                throw new \Exception('socket_select failed: ' . socket_strerror(socket_last_error($this->socket)));
            }

            if ($ready === 0) {
                continue;
            }

            $frame = $this->readFrame(timeout: 0);
            if ($frame === null) {
                continue;
            }

            $key = $frame->peekUint16();

            if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
                $this->dispatchServerPush($frame);
                $dispatched++;
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
                $this->sendMessage(new HeartbeatRequestV1());
                if ($this->heartbeatCallback !== null) {
                    ($this->heartbeatCallback)();
                }
                break;

            case KeyEnum::PUBLISH_CONFIRM->value:
                $confirm = PublishConfirmResponseV1::fromStreamBuffer($frame);
                $publisherId = $confirm->getPublisherId();
                if (isset($this->publisherCallbacks[$publisherId])) {
                    ($this->publisherCallbacks[$publisherId]['onConfirm'])($confirm->getPublishingIds());
                }
                break;

            case KeyEnum::PUBLISH_ERROR->value:
                $error = PublishErrorResponseV1::fromStreamBuffer($frame);
                $publisherId = $error->getPublisherId();
                if (isset($this->publisherCallbacks[$publisherId])) {
                    ($this->publisherCallbacks[$publisherId]['onError'])($error->getErrors());
                }
                break;

            case KeyEnum::DELIVER->value:
                $deliver = DeliverResponseV1::fromStreamBuffer($frame);
                $subscriptionId = $deliver->getSubscriptionId();
                if (isset($this->subscriberCallbacks[$subscriptionId])) {
                    ($this->subscriberCallbacks[$subscriptionId])($deliver);
                }
                break;

            case KeyEnum::METADATA_UPDATE->value:
                $update = MetadataUpdateResponseV1::fromStreamBuffer($frame);
                if ($this->metadataUpdateCallback !== null) {
                    ($this->metadataUpdateCallback)($update);
                }
                break;

            case KeyEnum::CONSUMER_UPDATE->value:
                $query = ConsumerUpdateQueryV1::fromStreamBuffer($frame);
                $offsetType = 1;
                $offset = 0;
                if ($this->consumerUpdateCallback !== null) {
                    [$offsetType, $offset] = ($this->consumerUpdateCallback)($query);
                }
                $reply = new ConsumerUpdateReplyV1(
                    correlationId: $query->getCorrelationId(),
                    responseCode: 0x0001,
                    offsetType: $offsetType,
                    offset: $offset,
                );
                $content = $reply->toStreamBuffer()->getContents();
                $this->sendFrame((new WriteBuffer())->addUInt32(strlen($content))->addRaw($content)->getContents());
                break;
        }
    }

    public function readFrame(int $timeout = 30): ?ReadBuffer
    {
        $read = [$this->socket];
        $write = null;
        $except = null;

        $ready = socket_select($read, $write, $except, $timeout > 0 ? $timeout : 0);

        if ($ready === false) {
            throw new \Exception('socket_select failed: ' . socket_strerror(socket_last_error($this->socket)));
        }

        if ($ready === 0) {
            return null;
        }

        $sizeData = $this->readBytes(4);
        if ($sizeData === null) {
            return null;
        }

        $size = unpack('N', $sizeData)[1];

        $frameData = $this->readBytes($size);
        if ($frameData === null) {
            throw new \Exception("Failed to read frame data");
        }

        echo "Socket <-" . bin2hex($frameData) . PHP_EOL;

        return new ReadBuffer($frameData);
    }

    private function readBytes(int $length): ?string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = socket_read($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_ETIMEDOUT) {
                    return null;
                }
                throw new \Exception("Failed to read from socket: " . socket_strerror($error));
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}
