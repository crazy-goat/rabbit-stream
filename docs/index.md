# RabbitStream Documentation

Welcome to the RabbitStream documentation! RabbitStream is a pure PHP library implementing the RabbitMQ Streams Protocol client (port 5552) with zero external dependencies.

## Table of Contents

### Getting Started
- [Installation](en/getting-started/installation.md) - Install RabbitStream via Composer
- [Quick Start](en/getting-started/quick-start.md) - Get up and running in minutes
- [Requirements](en/getting-started/requirements.md) - System and PHP requirements
- [Configuration](en/getting-started/configuration.md) - Configuration options and best practices

### Guide
- [Architecture Overview](en/guide/architecture-overview.md) - Understanding the library structure
- [Connection Lifecycle](en/guide/connection-lifecycle.md) - Managing connections to RabbitMQ
- [Publishing](en/guide/publishing.md) - Sending messages to streams
- [Consuming](en/guide/consuming.md) - Receiving messages from streams
- [Stream Management](en/guide/stream-management.md) - Creating and managing streams
- [Super Streams](en/guide/super-streams.md) - Working with partitioned streams
- [Offset Tracking](en/guide/offset-tracking.md) - Managing message offsets
- [Error Handling](en/guide/error-handling.md) - Handling errors and failures
- [Flow Control](en/guide/flow-control.md) - Managing backpressure and flow control

### API Reference
- [Connection](en/api-reference/connection.md) - StreamConnection class reference
- [Producer](en/api-reference/producer.md) - Publishing messages
- [Consumer](en/api-reference/consumer.md) - Consuming messages
- [Message](en/api-reference/message.md) - Message structure and handling
- [StreamConnection](en/api-reference/stream-connection.md) - Low-level connection management
- [WriteBuffer](en/api-reference/write-buffer.md) - Binary serialization for requests
- [ReadBuffer](en/api-reference/read-buffer.md) - Binary deserialization for responses
- [ResponseBuilder](en/api-reference/response-builder.md) - Response parsing and dispatch
- [Enums](en/api-reference/enums.md) - Protocol enums and constants
- [Value Objects](en/api-reference/value-objects.md) - Data transfer objects

### Protocol Documentation
- [Overview](en/protocol/overview.md) - Introduction to the RabbitMQ Streams Protocol
- [Frame Structure](en/protocol/frame-structure.md) - Understanding protocol frames
- [Connection & Authentication](en/protocol/connection-auth.md) - Handshake and authentication
- [Publishing Commands](en/protocol/publishing-commands.md) - Protocol commands for publishing
- [Consuming Commands](en/protocol/consuming-commands.md) - Protocol commands for consuming
- [Stream Management Commands](en/protocol/stream-management-commands.md) - Stream administration
- [Routing Commands](en/protocol/routing-commands.md) - Super stream routing
- [Connection Management Commands](en/protocol/connection-management-commands.md) - Heartbeats and metadata
- [Server Push Frames](en/protocol/server-push-frames.md) - Asynchronous server messages

### Examples
- [Basic Producer](en/examples/basic-producer.md) - Simple message publishing
- [Basic Consumer](en/examples/basic-consumer.md) - Simple message consumption
- [Consumer Auto-Commit](en/examples/consumer-auto-commit.md) - Automatic offset management
- [Stream Management](en/examples/stream-management.md) - Creating and deleting streams
- [Named Producer Deduplication](en/examples/named-producer-deduplication.md) - Message deduplication
- [Offset Resume](en/examples/offset-resume.md) - Resuming from a specific offset
- [Super Stream Routing](en/examples/super-stream-routing.md) - Working with partitioned streams
- [Low-Level Protocol](en/examples/low-level-protocol.md) - Direct protocol usage
- [Error Handling Patterns](en/examples/error-handling-patterns.md) - Common error scenarios

### Advanced Topics
- [Binary Serialization](en/advanced/binary-serialization.md) - Understanding the binary protocol
- [Custom Serializer](en/advanced/custom-serializer.md) - Implementing custom serialization
- [AMQP Message Decoding](en/advanced/amqp-message-decoding.md) - Working with AMQP 0.9.1 messages
- [Osiris Chunk Format](en/advanced/osiris-chunk-format.md) - Internal storage format
- [PSR Logging](en/advanced/psr-logging.md) - Integrating with PSR-3 loggers
- [Performance Tuning](en/advanced/performance-tuning.md) - Optimizing for high throughput

### Contributing
- [Development Setup](en/contributing/development-setup.md) - Setting up the development environment
- [Testing](en/contributing/testing.md) - Running tests and writing new ones
- [Adding Protocol Commands](en/contributing/adding-protocol-commands.md) - Extending the protocol implementation
- [Code Style](en/contributing/code-style.md) - Coding standards and conventions

## Language Selection

This documentation is available in multiple languages. See [LANGUAGES.md](LANGUAGES.md) for a list of available translations and information on how to contribute translations.

## Quick Links

- **GitHub Repository**: https://github.com/crazy-goat/rabbit-stream
- **Packagist**: https://packagist.org/packages/crazy-goat/rabbit-stream
- **RabbitMQ Streams Protocol Spec**: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
- **Issue Tracker**: https://github.com/crazy-goat/rabbit-stream/issues

## Support

If you encounter issues or have questions:

1. Check the [examples](en/examples/) for common use cases
2. Review the [API reference](en/api-reference/) for detailed class documentation
3. Search existing [GitHub issues](https://github.com/crazy-goat/rabbit-stream/issues)
4. Create a new issue with a detailed description

## License

RabbitStream is open-source software licensed under the MIT License.
