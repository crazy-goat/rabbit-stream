<?php

class RabbitMQStreamsClient
{
    private $socket;
    private $correlationId = 1;
    private $publisherId = 1;
    private $publishingId = 1;
    private $frameMax = 1048576; // 1MB default
    private $heartbeat = 60; // 60 seconds default
    private $connected = false;
    private $authenticated = false;
    
    // Response codes
    const RESPONSE_OK = 0x01;
    const RESPONSE_STREAM_NOT_EXIST = 0x02;
    const RESPONSE_STREAM_ALREADY_EXISTS = 0x05;
    const RESPONSE_AUTHENTICATION_FAILURE = 0x08;
    
    // Command keys
    const CMD_PEER_PROPERTIES = 0x0011;
    const CMD_SASL_HANDSHAKE = 0x0012;
    const CMD_SASL_AUTHENTICATE = 0x0013;
    const CMD_TUNE = 0x0014;
    const CMD_OPEN = 0x0015;
    const CMD_DECLARE_PUBLISHER = 0x0001;
    const CMD_PUBLISH = 0x0002;
    const CMD_PUBLISH_CONFIRM = 0x0003;
    const CMD_DELETE_PUBLISHER = 0x0006;
    const CMD_HEARTBEAT = 0x0017;
    const CMD_CREATE = 0x000d;

    public function __construct($host = 'localhost', $port = 5552)
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            throw new Exception("Cannot create socket: " . socket_strerror(socket_last_error()));
        }
        
        $result = socket_connect($this->socket, $host, $port);
        if (!$result) {
            throw new Exception("Cannot connect to $host:$port: " . socket_strerror(socket_last_error($this->socket)));
        }
        
        $this->connected = true;
        echo "Connected to RabbitMQ Streams at $host:$port\n";
    }
    
    public function authenticate($username = 'guest', $password = 'guest', $vhost = '/')
    {
        if (!$this->connected) {
            throw new Exception("Not connected to server");
        }
        
        try {
            // 1. Peer Properties Exchange
            $this->exchangePeerProperties();
            
            // 2. SASL Handshake
            $this->saslHandshake();
            
            // 3. SASL Authenticate
            $this->saslAuthenticate($username, $password);
            
            // 4. Tune
            $this->tune();
            
            // 5. Open
            $this->open($vhost);
            
            $this->authenticated = true;
            echo "Authentication successful\n";
            
        } catch (Exception $e) {
            throw new Exception("Authentication failed: " . $e->getMessage());
        }
    }
    
    public function declarePublisher($stream, $publisherReference = null)
    {
        if (!$this->authenticated) {
            throw new Exception("Not authenticated");
        }
        
        $correlationId = $this->correlationId++;
        
        // Build DeclarePublisher request
        $data = pack('n', self::CMD_DECLARE_PUBLISHER); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId
        $data .= pack('C', $this->publisherId); // PublisherId
        
        // PublisherReference (optional)

        $data .= pack('n', strlen($publisherReference ?? '')) . $publisherReference ?? '';

        // Stream name
        $data .= pack('n', strlen($stream)) . $stream;
        
        $this->sendFrame($data);
        
        $response = $this->readFrame();
        $responseCode = $this->parseResponse($response, $correlationId);
        
        if ($responseCode !== self::RESPONSE_OK) {
            throw new Exception("Failed to declare publisher. Response code: 0x" . dechex($responseCode));
        }
        
        echo "Publisher declared successfully for stream: $stream\n";
        return $this->publisherId;
    }
    
    public function publish($messages, $filterValue = null)
    {
        if (!$this->authenticated) {
            throw new Exception("Not authenticated");
        }
        
        $version = ($filterValue !== null) ? 2 : 1;
        
        // Build Publish command
        $data = '';
        $data .= pack('n', self::CMD_PUBLISH); // Key
        $data .= pack('n', $version); // Version
        $data .= pack('C', $this->publisherId); // PublisherId
        
        // Published Messages array
        if (!is_array($messages)) {
            $messages = [$messages];
        }
        
        $data .= pack('N', count($messages)); // Array length
        
        $publishingIds = [];
        foreach ($messages as $message) {
            $currentPublishingId = $this->publishingId++;
            $publishingIds[] = $currentPublishingId;
            
            $data .= pack('J', $currentPublishingId); // PublishingId (uint64, big endian)
            
            if ($version === 2 && $filterValue !== null) {
                $data .= pack('n', strlen($filterValue)) . $filterValue; // FilterValue
            }
            
            // Message as bytes
            $messageBytes = is_string($message) ? $message : json_encode($message);
            $data .= pack('N', strlen($messageBytes)) . $messageBytes;
        }
        
        $this->sendFrame($data);
        
        echo "Published " . count($messages) . " message(s)\n";
        return $publishingIds;
    }
    
    public function waitForPublishConfirm($timeout = 5)
    {
        $startTime = time();
        
        while (time() - $startTime < $timeout) {
            $frame = $this->readFrame(10); // 1 second timeout for each read
            if ($frame === null) {
                continue;
            }
            
            $key = unpack('n', substr($frame, 0, 2))[1];
            
            if ($key === (self::CMD_PUBLISH_CONFIRM | 0x8000)) {
                // This is a publish confirm
                $this->handlePublishConfirm($frame);
                return true;
            } elseif ($key === (0x0004 | 0x8000)) {
                // This is a publish error
                $this->handlePublishError($frame);
                return false;
            } elseif ($key === self::CMD_HEARTBEAT) {
                // Handle heartbeat
                $this->handleHeartbeat();
            }
        }
        
        throw new Exception("Timeout waiting for publish confirm");
    }
    
    private function handlePublishConfirm($frame)
    {
        $offset = 0;
        $key = unpack('n', substr($frame, $offset, 2))[1]; $offset += 2;
        $version = unpack('n', substr($frame, $offset, 2))[1]; $offset += 2;
        $publisherId = unpack('C', substr($frame, $offset, 1))[1]; $offset += 1;
        
        // PublishingIds array
        $arrayLength = unpack('N', substr($frame, $offset, 4))[1]; $offset += 4;
        
        $confirmedIds = [];
        for ($i = 0; $i < $arrayLength; $i++) {
            $publishingId = unpack('J', substr($frame, $offset, 8))[1]; $offset += 8;
            $confirmedIds[] = $publishingId;
        }
        
        echo "Publish confirmed for publishing IDs: " . implode(', ', $confirmedIds) . "\n";
    }
    
    private function handlePublishError($frame)
    {
        $offset = 0;
        $key = unpack('n', substr($frame, $offset, 2))[1]; $offset += 2;
        $version = unpack('n', substr($frame, $offset, 2))[1]; $offset += 2;
        $publisherId = unpack('C', substr($frame, $offset, 1))[1]; $offset += 1;
        
        // PublishingError array
        $arrayLength = unpack('N', substr($frame, $offset, 4))[1]; $offset += 4;
        
        for ($i = 0; $i < $arrayLength; $i++) {
            $publishingId = unpack('J', substr($frame, $offset, 8))[1]; $offset += 8;
            $errorCode = unpack('n', substr($frame, $offset, 2))[1]; $offset += 2;
            
            echo "Publish error for publishing ID $publishingId: code 0x" . dechex($errorCode) . "\n";
        }
    }
    
    private function handleHeartbeat()
    {
        // Send heartbeat response
        $data = '';
        $data .= pack('n', self::CMD_HEARTBEAT); // Key
        $data .= pack('n', 1); // Version
        
        $this->sendFrame($data);
        echo "Heartbeat sent\n";
    }
    
    public function deletePublisher()
    {
        if (!$this->authenticated) {
            throw new Exception("Not authenticated");
        }
        
        $correlationId = $this->correlationId++;
        
        $data = '';
        $data .= pack('n', self::CMD_DELETE_PUBLISHER); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId
        $data .= pack('C', $this->publisherId); // PublisherId
        
        $this->sendFrame($data);
        
        $response = $this->readFrame();
        $responseCode = $this->parseResponse($response, $correlationId);
        
        if ($responseCode !== self::RESPONSE_OK) {
            throw new Exception("Failed to delete publisher. Response code: 0x" . dechex($responseCode));
        }
        
        echo "Publisher deleted successfully\n";
    }
    
    private function exchangePeerProperties()
    {
        $correlationId = $this->correlationId++;
        
        $data = '';
        $data .= pack('n', self::CMD_PEER_PROPERTIES); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId
        
        // Peer Properties (empty for now)
        $data .= pack('N', 0); // Empty array
        
        $this->sendFrame($data);
        
        $response = $this->readFrame();
        $this->parseResponse($response, $correlationId);
    }
    
    private function saslHandshake()
    {
        $correlationId = $this->correlationId++;
        
        $data = '';
        $data .= pack('n', self::CMD_SASL_HANDSHAKE); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId
        
        $this->sendFrame($data);
        
        $response = $this->readFrame();
        $this->parseResponse($response, $correlationId);
    }
    
    private function saslAuthenticate($username, $password)
    {
        $correlationId = $this->correlationId++;
        
        $data = '';
        $data .= pack('n', self::CMD_SASL_AUTHENTICATE); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId
        
        // Mechanism
        $mechanism = 'PLAIN';
        $data .= pack('n', strlen($mechanism)) . $mechanism;
        
        // SASL PLAIN authentication data
        $authData = "\0" . $username . "\0" . $password;
        $data .= pack('N', strlen($authData)) . $authData;
        
        $this->sendFrame($data);
        
        $response = $this->readFrame();
        $responseCode = $this->parseResponse($response, $correlationId);
        
        if ($responseCode !== self::RESPONSE_OK) {
            throw new Exception("SASL authentication failed. Response code: 0x" . dechex($responseCode));
        }
    }
    
    private function tune()
    {
        // Wait for server's Tune frame
        $frame = $this->readFrame();
        $key = unpack('n', substr($frame, 0, 2))[1];
        
        if ($key !== self::CMD_TUNE) {
            throw new Exception("Expected Tune frame, got: 0x" . dechex($key));
        }
        
        $version = unpack('n', substr($frame, 2, 2))[1];
        $serverFrameMax = unpack('N', substr($frame, 4, 4))[1];
        $serverHeartbeat = unpack('N', substr($frame, 8, 4))[1];
        
        // Accept server's settings
        $this->frameMax = $serverFrameMax;
        $this->heartbeat = $serverHeartbeat;
        
        // Send Tune response
        $data = '';
        $data .= pack('n', self::CMD_TUNE); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $this->frameMax); // FrameMax
        $data .= pack('N', $this->heartbeat); // Heartbeat
        
        $this->sendFrame($data);
    }

    public function createStream($streamName, $arguments = [])
    {
        if (!$this->authenticated) {
            throw new Exception("Not authenticated");
        }

        $correlationId = $this->correlationId++;

        // Build Create request
        $data = '';
        $data .= pack('n', self::CMD_CREATE); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId

        // Stream name
        $data .= pack('n', strlen($streamName)) . $streamName;

        // Arguments array
        $data .= pack('N', count($arguments)); // Array length

        foreach ($arguments as $key => $value) {
            // Argument Key
            $data .= pack('n', strlen($key)) . $key;

            // Argument Value
            $valueStr = (string)$value;
            $data .= pack('n', strlen($valueStr)) . $valueStr;
        }

        $this->sendFrame($data);

        $response = $this->readFrame();
        $responseCode = $this->parseResponse($response, $correlationId);

        if ($responseCode === self::RESPONSE_OK) {
            echo "Stream '$streamName' created successfully\n";
            return true;
        } elseif ($responseCode === self::RESPONSE_STREAM_ALREADY_EXISTS) {
            echo "Stream '$streamName' already exists\n";
            return true;
        } else {
            throw new Exception("Failed to create stream '$streamName'. Response code: 0x" . dechex($responseCode));
        }
    }

    private function open($vhost)
    {
        $correlationId = $this->correlationId++;
        
        $data = '';
        $data .= pack('n', self::CMD_OPEN); // Key
        $data .= pack('n', 1); // Version
        $data .= pack('N', $correlationId); // CorrelationId
        $data .= pack('n', strlen($vhost)) . $vhost; // VirtualHost
        
        $this->sendFrame($data);
        
        $response = $this->readFrame();
        $responseCode = $this->parseResponse($response, $correlationId);
        
        if ($responseCode !== self::RESPONSE_OK) {
            throw new Exception("Failed to open virtual host. Response code: 0x" . dechex($responseCode));
        }
    }
    
    private function sendFrame($data)
    {
        $size = strlen($data);
        $frame = pack('N', $size) . $data;
        
        $written = socket_write($this->socket, $frame, strlen($frame));
        if ($written === false) {
            throw new Exception("Failed to write to socket: " . socket_strerror(socket_last_error($this->socket)));
        }
    }
    
    private function readFrame($timeout = 30)
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
            throw new Exception("Failed to read frame data");
        }
        
        return $frameData;
    }
    
    private function readBytes($length)
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
                throw new Exception("Failed to read from socket: " . socket_strerror($error));
            }
            
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        
        return $data;
    }
    
    private function parseResponse($frame, $expectedCorrelationId)
    {
        $key = unpack('n', substr($frame, 0, 2))[1];
        $version = unpack('n', substr($frame, 2, 2))[1];
        $correlationId = unpack('N', substr($frame, 4, 4))[1];
        $responseCode = unpack('n', substr($frame, 8, 2))[1];
        
        if ($correlationId !== $expectedCorrelationId) {
            throw new Exception("Correlation ID mismatch. Expected: $expectedCorrelationId, got: $correlationId");
        }
        
        return $responseCode;
    }
    
    public function close()
    {
        if ($this->socket) {
            socket_close($this->socket);
            $this->connected = false;
            $this->authenticated = false;
            echo "Connection closed\n";
        }
    }
    
    public function __destruct()
    {
        $this->close();
    }
}

// Example usage
try {
    $client = new RabbitMQStreamsClient('localhost', 5552);
    $client->authenticate('guest', 'guest', '/');

    // Declare publisher for a stream
    $client->createStream('my-stream');
    $publisherId = $client->declarePublisher('my-stream');
    
    // Publish some messages
    $messages = [
        "Hello World!",
        ["id" => 1, "message" => "JSON message"],
        "Another message"
    ];
    
    $publishingIds = $client->publish($messages);
    echo "Published messages with IDs: " . implode(', ', $publishingIds) . "\n";
    

    // Clean up
    $client->deletePublisher();
    $client->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

?>
