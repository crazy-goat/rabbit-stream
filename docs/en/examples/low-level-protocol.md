# Low-Level Protocol Example

This example demonstrates using the RabbitMQ Stream protocol directly with `StreamConnection`, `WriteBuffer`, and `ReadBuffer`. This is useful for:

- Learning the protocol
- Debugging connection issues
- Implementing custom behavior
- Understanding how the high-level API works

## Complete Working Example

The script uses `__DIR__ . '/../vendor/autoload.php'`, so save it in a subdirectory of the repository root (for example `examples/`) before running it with `php`.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;

/**
 * Low-level RabbitMQ Stream connection example
 * 
 * This demonstrates the complete handshake process using raw protocol commands.
 */
class LowLevelConnectionExample
{
    private StreamConnection $stream;
    private PhpBinarySerializer $serializer;
    
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 5552,
        private string $user = 'guest',
        private string $password = 'guest',
        private string $vhost = '/'
    ) {
        $this->serializer = new PhpBinarySerializer();
    }
    
    /**
     * Perform complete connection handshake
     */
    public function connect(): void
    {
        echo "=== RabbitMQ Stream Low-Level Connection Example ===\n\n";
        
        // Step 1: TCP Connection
        $this->step1_tcpConnect();
        
        // Step 2: PeerProperties
        $this->step2_peerProperties();
        
        // Step 3: SaslHandshake
        $this->step3_saslHandshake();
        
        // Step 4: SaslAuthenticate
        $this->step4_saslAuthenticate();
        
        // Step 5: Tune
        $this->step5_tune();
        
        // Step 6: Open
        $this->step6_open();
        
        echo "\n✓ Connection established successfully!\n";
    }
    
    /**
     * Step 1: Establish TCP connection
     */
    private function step1_tcpConnect(): void
    {
        echo "Step 1: TCP Connect to {$this->host}:{$this->port}\n";
        
        $this->stream = new StreamConnection($this->host, $this->port);
        $this->stream->connect();
        
        echo "  ✓ TCP connection established\n\n";
    }
    
    /**
     * Step 2: Exchange peer properties
     */
    private function step2_peerProperties(): void
    {
        echo "Step 2: PeerProperties (0x0011)\n";
        
        // Send PeerProperties request
        $request = new PeerPropertiesRequestV1();
        $this->stream->sendMessage($request);
        echo "  → Sent PeerProperties request\n";
        
        // Receive response
        $response = $this->readResponse('PeerProperties');
        if (!$response instanceof PeerPropertiesResponseV1) {
            throw new \Exception('Expected PeerPropertiesResponseV1');
        }
        
        $properties = [];
        foreach ($response->getPeerProperty() as $property) {
            $properties[$property->getKey()] = $property->getValue();
        }
        echo "  ← Received PeerProperties response\n";
        echo "  ✓ Server properties: " . json_encode($properties) . "\n\n";
    }
    
    /**
     * Step 3: SASL Handshake
     */
    private function step3_saslHandshake(): void
    {
        echo "Step 3: SaslHandshake (0x0012)\n";
        
        // Send SaslHandshake request
        $request = new SaslHandshakeRequestV1();
        $this->stream->sendMessage($request);
        echo "  → Sent SaslHandshake request\n";
        
        // Receive response
        $response = $this->readResponse('SaslHandshake');
        if (!$response instanceof SaslHandshakeResponseV1) {
            throw new \Exception('Expected SaslHandshakeResponseV1');
        }
        
        $mechanisms = $response->getMechanisms();
        echo "  ← Received SaslHandshake response\n";
        echo "  ✓ Available mechanisms: " . implode(', ', $mechanisms) . "\n\n";
        
        // Verify PLAIN is supported
        if (!in_array('PLAIN', $mechanisms, true)) {
            throw new \Exception('PLAIN mechanism not supported by server');
        }
    }
    
    /**
     * Step 4: SASL Authentication
     */
    private function step4_saslAuthenticate(): void
    {
        echo "Step 4: SaslAuthenticate (0x0013)\n";
        echo "  → Authenticating as '{$this->user}'\n";
        
        // Send authentication request
        // PLAIN format: \0username\0password
        $request = new SaslAuthenticateRequestV1('PLAIN', $this->user, $this->password);
        $this->stream->sendMessage($request);
        
        // Receive response
        $response = $this->readResponse('SaslAuthenticate');
        if (!$response instanceof SaslAuthenticateResponseV1) {
            throw new \Exception('Expected SaslAuthenticateResponseV1');
        }
        
        echo "  ← Authentication successful\n\n";
    }
    
    /**
     * Step 5: Tune connection parameters
     */
    private function step5_tune(): void
    {
        echo "Step 5: Tune (0x0014)\n";
        
        // Server sends TuneRequestV1 first
        $tuneRequest = $this->stream->readMessage();
        if (!$tuneRequest instanceof TuneRequestV1) {
            throw new \Exception('Expected TuneRequestV1 from server');
        }
        
        $serverFrameMax = $tuneRequest->getFrameMax();
        $serverHeartbeat = $tuneRequest->getHeartbeat();
        
        echo "  ← Server Tune request:\n";
        echo "     - frameMax: {$serverFrameMax}\n";
        echo "     - heartbeat: {$serverHeartbeat}\n";
        
        // Negotiate values (use server's values for this example)
        $clientFrameMax = 1048576;  // 1MB
        $clientHeartbeat = 60;      // 60 seconds
        
        $negotiatedFrameMax = $this->negotiateValue($clientFrameMax, $serverFrameMax);
        $negotiatedHeartbeat = $this->negotiateValue($clientHeartbeat, $serverHeartbeat);
        
        echo "  → Negotiated values:\n";
        echo "     - frameMax: {$negotiatedFrameMax}\n";
        echo "     - heartbeat: {$negotiatedHeartbeat}\n";
        
        // Send TuneResponse
        $response = new TuneResponseV1($negotiatedFrameMax, $negotiatedHeartbeat);
        $this->stream->sendMessage($response);
        
        // Apply frame size limit
        if ($negotiatedFrameMax > 0) {
            $this->stream->setMaxFrameSize($negotiatedFrameMax);
        }
        
        echo "  ✓ Tune complete\n\n";
    }
    
    /**
     * Step 6: Open virtual host
     */
    private function step6_open(): void
    {
        echo "Step 6: Open (0x0015)\n";
        echo "  → Opening virtual host '{$this->vhost}'\n";
        
        // Send Open request
        $request = new OpenRequestV1($this->vhost);
        $this->stream->sendMessage($request);
        
        // Receive response
        $response = $this->readResponse('Open');
        if (!$response instanceof OpenResponseV1) {
            throw new \Exception('Expected OpenResponseV1');
        }
        
        echo "  ✓ Virtual host '{$this->vhost}' opened successfully\n";
    }
    
    /**
     * Gracefully close the connection
     */
    public function disconnect(): void
    {
        echo "\n=== Disconnecting ===\n";
        
        // Send Close request
        $request = new CloseRequestV1(0, 'Normal shutdown');
        $this->stream->sendMessage($request);
        echo "→ Sent Close request\n";
        
        // Wait for acknowledgment
        $response = $this->stream->readMessage();
        if ($response instanceof CloseResponseV1) {
            echo "← Received Close response\n";
            echo "✓ Graceful disconnect complete\n";
        }
        
        // Close socket
        $this->stream->close();
    }
    
    /**
     * Negotiate a value between client and server
     */
    private function negotiateValue(int $clientValue, int $serverValue): int
    {
        // If either value is 0, use the non-zero value
        // Otherwise, use the minimum (more restrictive)
        if ($clientValue === 0 || $serverValue === 0) {
            return max($clientValue, $serverValue);
        }
        return min($clientValue, $serverValue);
    }
    
    /**
     * Read a correlated response, turning a non-OK response code into an
     * exception. readMessage() already throws ProtocolException while
     * deserializing a non-OK code, so a returned response always carries OK.
     */
    private function readResponse(string $command): object
    {
        try {
            return $this->stream->readMessage();
        } catch (ProtocolException $e) {
            $code = $e->getResponseCode();
            throw new \Exception(
                "{$command} failed: " . ($code?->getMessage() ?? $e->getMessage()),
                previous: $e
            );
        }
    }
}

// Run the example
if (php_sapi_name() === 'cli') {
    try {
        $example = new LowLevelConnectionExample(
            host: '127.0.0.1',
            port: 5552,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
        
        $example->connect();
        $example->disconnect();
        
    } catch (\Exception $e) {
        echo "\n✗ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
```

## Using WriteBuffer Directly

For even lower-level control, you can construct frames manually with `WriteBuffer`:

```php
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

// Build a frame manually
$buffer = new WriteBuffer();

// Frame header
$buffer->addUInt16(KeyEnum::SASL_AUTHENTICATE->value);  // Key: 0x0013
$buffer->addUInt16(1);                                   // Version: 1
$buffer->addUInt32(1);                                   // Correlation ID

// SASL Authenticate payload
$buffer->addString('PLAIN');                           // Mechanism
$saslData = "\0guest\0guest";                          // PLAIN format
$buffer->addString($saslData);                         // Credentials

// Get frame content
$frameContent = $buffer->getContents();

// Wrap with size prefix for sending
$frame = (new WriteBuffer())
    ->addUInt32(strlen($frameContent))
    ->addRaw($frameContent)
    ->getContents();

// Send the raw frame
$stream->sendFrame($frame);
```

## Using ReadBuffer Directly

Parse raw response data with `ReadBuffer`:

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

// Read raw frame data from socket
$frameData = $stream->readFrame();

if ($frameData instanceof ReadBuffer) {
    // Parse frame header
    $key = $frameData->getUint16();           // Command key (e.g., 0x8013)
    $version = $frameData->getUint16();       // Protocol version
    $correlationId = $frameData->getUint32();   // Correlation ID
    $responseCode = $frameData->getUint16();  // Response code
    
    // Check for success
    if ($responseCode === ResponseCodeEnum::OK->value) {
        echo "Success!\n";
    } else {
        $error = ResponseCodeEnum::fromInt($responseCode);
        echo "Error: " . $error->getMessage() . "\n";
    }
    
    // Read remaining data if any
    $remaining = $frameData->getRemainingBytes();
}
```

## Error Handling in Low-Level Code

```php
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    // Send request
    $stream->sendMessage($request);
    
    // Read response with timeout. A non-OK response code throws
    // ProtocolException during deserialization.
    $response = $stream->readMessage(timeout: 30.0);
    
    // Check response type
    if (!$response instanceof ExpectedResponseV1) {
        throw new \Exception('Unexpected response type');
    }
    
} catch (ProtocolException $e) {
    // Inspect the broker's response code carried by the exception
    switch ($e->getResponseCode()) {
        case ResponseCodeEnum::AUTHENTICATION_FAILURE:
            throw new \Exception('Invalid credentials');
            
        case ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE:
            throw new \Exception('Access denied to virtual host');
            
        default:
            throw new \Exception('Error: ' . $e->getMessage());
    }
    
} catch (TimeoutException $e) {
    echo "Operation timed out: " . $e->getMessage();
    
} catch (ConnectionException $e) {
    echo "Connection error: " . $e->getMessage();
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

## When to Use Low-Level API

### Use Low-Level When:
- Learning the protocol
- Debugging connection issues
- Implementing custom authentication
- Building a new client library
- Need fine-grained control over negotiation

### Use High-Level When:
- Building applications
- Normal stream operations
- Want automatic handshake
- Need producer/consumer management
- Want automatic error handling

## Protocol Key Quick Reference

| Command | Request | Response | Description |
|---------|---------|----------|-------------|
| PeerProperties | 0x0011 | 0x8011 | Exchange capabilities |
| SaslHandshake | 0x0012 | 0x8012 | Get auth mechanisms |
| SaslAuthenticate | 0x0013 | 0x8013 | Authenticate |
| Tune | 0x0014 | 0x8014 | Negotiate settings |
| Open | 0x0015 | 0x8015 | Open virtual host |
| Close | 0x0016 | 0x8016 | Close connection |
| Heartbeat | 0x0017 | — | Keepalive (no response) |

## See Also

- [Connection Lifecycle Guide](../guide/connection-lifecycle.md) - High-level overview
- [Connection & Authentication](../protocol/connection-auth.md) - Protocol details
- [Connection Management Commands](../protocol/connection-management-commands.md) - Close, heartbeat
