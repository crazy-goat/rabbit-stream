<?php

namespace CrazyGoat\StreamyCarrot;

use CrazyGoat\StreamyCarrot\Request\WriteBuffer;
use CrazyGoat\StreamyCarrot\Request\RequestInterface;
use CrazyGoat\StreamyCarrot\Response\ReadBuffer;
use CrazyGoat\StreamyCarrot\Response\ResponseBuilder;

class StreamConnection
{
    private bool $connected = false;

    private ?\Socket $socket = null;

    private int $correlationId = 0;

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

    public function sendMessage(object $request): ?ReadBuffer
    {
        $this->correlationId++;

        if ($request instanceof RequestInterface || $request instanceof CorrelationInterface) {
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

        if (!$request instanceof RequestInterface || !$request->getCommandCode()->hasReturn()) {
            return null;
        }

        return $this->readFrame();
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

    public function readMessage($timeout = 30): object
    {
        return ResponseBuilder::fromResponseBuffer($this->readFrame($timeout));
    }

    public function readFrame($timeout = 30): ?ReadBuffer
    {
        // Set socket timeout
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));

        // Read frame size
        $sizeData = $this->readBytes(4);
        if ($sizeData === null) {
            return null;
        }

        $size = unpack('N', $sizeData)[1];

        // Read frame data
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
                    return null; // Timeout
                }
                throw new \Exception("Failed to read from socket: " . socket_strerror($error));
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}