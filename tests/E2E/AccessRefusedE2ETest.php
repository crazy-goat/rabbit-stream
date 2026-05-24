<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

/**
 * @group destructive
 */
class AccessRefusedE2ETest extends E2ETestCase
{
    private const string RESTRICTED_USER = 'restricted-user';
    private const string RESTRICTED_PASS = 'restricted-pass';
    private const string RESTRICTED_VHOST = 'restricted-vhost';

    private static int $managementPort = 15672;
    private static bool $managementAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$managementPort = (int)(getenv('RABBITMQ_MANAGEMENT_PORT') ?: self::$managementPort);
        self::ensureManagementFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        self::deleteManagement('/users/' . self::RESTRICTED_USER);
        self::deleteManagement('/vhosts/' . self::RESTRICTED_VHOST);
        parent::tearDownAfterClass();
    }

    private static function ensureManagementFixtures(): void
    {
        if (!self::putManagement('/vhosts/' . self::RESTRICTED_VHOST)) {
            return;
        }

        if (
            !self::putManagement('/users/' . self::RESTRICTED_USER, [
            'password' => self::RESTRICTED_PASS,
            'tags' => 'none',
            ])
        ) {
            self::deleteManagement('/vhosts/' . self::RESTRICTED_VHOST);
            return;
        }

        $permPath = '/permissions/' . self::RESTRICTED_VHOST . '/' . self::RESTRICTED_USER;
        if (
            !self::putManagement($permPath, [
            'configure' => '',
            'write' => '.*',
            'read' => '.*',
            ])
        ) {
            self::deleteManagement('/users/' . self::RESTRICTED_USER);
            self::deleteManagement('/vhosts/' . self::RESTRICTED_VHOST);
            return;
        }

        self::$managementAvailable = true;
    }

    /** @param array<string, mixed>|null $body */
    private static function managementRequest(string $method, string $path, ?array $body = null): bool
    {
        $url = sprintf('http://%s:%d/api%s', self::$host, self::$managementPort, $path);
        $headers = [
            'Authorization: Basic ' . base64_encode('guest:guest'),
            'Content-Type: application/json',
        ];

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
        ];

        if ($body !== null) {
            $http['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $context = stream_context_create(['http' => $http]);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return false;
        }

        $statusLine = $http_response_header[0] ?? '';
        if ($statusLine !== '' && preg_match('#^HTTP/\d\.\d\s+(\d+)#', $statusLine, $m)) {
            return (int)$m[1] < 400;
        }

        return true;
    }

    private static function deleteManagement(string $path): void
    {
        self::managementRequest('DELETE', $path);
    }

    /** @param array<string, mixed>|null $body */
    private static function putManagement(string $path, ?array $body = null): bool
    {
        return self::managementRequest('PUT', $path, $body);
    }

    public function testAccessRefusedForCreateWithoutConfigurePermission(): void
    {
        if (!self::$managementAvailable) {
            $this->markTestSkipped('Management API not available');
        }

        $connection = $this->createConnection(
            user: self::RESTRICTED_USER,
            password: self::RESTRICTED_PASS,
            vhost: self::RESTRICTED_VHOST,
        );

        $streamName = 'test-forbidden-stream-' . uniqid();

        try {
            $connection->createStream($streamName);
            $this->fail('Expected ProtocolException to be thrown');
        } catch (ProtocolException $e) {
            $this->assertSame(
                ResponseCodeEnum::ACCESS_REFUSED,
                $e->getResponseCode(),
                'Expected ACCESS_REFUSED response code',
            );
            $this->assertStringContainsString(
                'ACCESS_REFUSED',
                $e->getMessage(),
                'Error message should include the response code name',
            );
        } finally {
            $connection->close();
        }
    }
}
