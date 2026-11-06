# Application Tests

This guide shows how to test an application that uses RabbitStream. It covers a test broker, the clean-up of test streams, and tests that do not need a broker.

For tests of the library itself, see [Testing](../contributing/testing.md).

## Test Broker

Run a RabbitMQ 4 broker with the stream plugin enabled. The command below uses the same image and plugin as the library tests. (Not tested.)

```bash
docker run -d --name app-test-broker \
  -p 5552:5552 \
  -e RABBITMQ_DEFAULT_USER=guest \
  -e RABBITMQ_DEFAULT_PASS=guest \
  rabbitmq:4-management \
  sh -c "rabbitmq-plugins enable rabbitmq_stream && rabbitmq-server"
```

Read the host and the port from environment variables in your tests. Use the defaults for a local broker.

```php
$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int) (getenv('RABBITMQ_PORT') ?: 5552);
```

## Stream Clean-Up

Give each test its own stream name. Create the stream in `setUp()`, and delete it in `tearDown()`. The `tearDown()` method runs also when the test fails, so a failed test does not leave a stream for the next test.

```php
use CrazyGoat\RabbitStream\Client\Connection;
use PHPUnit\Framework\TestCase;

final class OrderStreamTest extends TestCase
{
    private Connection $connection;
    private string $stream;

    protected function setUp(): void
    {
        $this->connection = Connection::create(
            host: getenv('RABBITMQ_HOST') ?: '127.0.0.1',
            port: (int) (getenv('RABBITMQ_PORT') ?: 5552),
        );
        $this->stream = 'test-orders-' . bin2hex(random_bytes(4));
        $this->connection->createStream($this->stream);
    }

    protected function tearDown(): void
    {
        if ($this->connection->streamExists($this->stream)) {
            $this->connection->deleteStream($this->stream);
        }
        $this->connection->close();
    }
}
```

`createStream()` throws `ProtocolException` when the stream already exists. A unique name avoids that error between runs.

## Tests Without a Broker

Write your code against the interfaces. The interfaces are `ConnectionInterface`, `ProducerInterface` and `ConsumerInterface`, in the `CrazyGoat\RabbitStream\Contract` namespace. Then a unit test passes a mock, and it does not need a broker.

```php
use CrazyGoat\RabbitStream\Contract\ProducerInterface;

final class OrderPublisher
{
    public function __construct(private ProducerInterface $producer)
    {
    }

    public function publish(string $orderJson): void
    {
        $this->producer->send($orderJson);
        $this->producer->waitForConfirms(timeout: 5.0);
    }
}
```

The unit test checks the calls on the mock. The class `OrderPublisher` is defined above, so this fence is marked as illustrative:

<!-- docs-lint: ignore -->
```php
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use PHPUnit\Framework\TestCase;

final class OrderPublisherTest extends TestCase
{
    public function testSendsTheOrderAndWaitsForTheConfirm(): void
    {
        $producer = $this->createMock(ProducerInterface::class);
        $producer->expects(self::once())->method('send')->with('{"id":1}');
        $producer->expects(self::once())->method('waitForConfirms');

        $publisher = new OrderPublisher($producer);
        $publisher->publish('{"id":1}');
    }
}
```

Keep the connection and the stream code in a small class. Pass that class to the code that needs a broker. Use `ConnectionInterface` as the type of the parameter, so a test can pass a mock.

## See Also

- [Testing](../contributing/testing.md) - Tests of the library itself
- [Stream Management](stream-management.md) - Create and delete streams
- [Publishing](publishing.md) - Producer behaviour
