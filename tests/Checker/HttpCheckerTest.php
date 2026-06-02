<?php

namespace App\Tests\Checker;

use App\Checker\HttpChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HttpCheckerTest extends TestCase
{
    public function testCheckSuccess(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('service is online');

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $checker = new HttpChecker($mockClient);
        $outcome = $checker->check([
            'url' => 'https://example.com/health',
            'expect_status' => 200,
            'expect_body_contains' => 'online'
        ]);

        $this->assertTrue($outcome->success);
        $this->assertEquals('OK', $outcome->message);
        $this->assertNotNull($outcome->responseTime);
        $this->assertEquals(200, $outcome->extra['status_code']);
    }

    public function testCheckStatusMismatch(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(500);
        $mockResponse->method('getContent')->willReturn('Internal Server Error');

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $checker = new HttpChecker($mockClient);
        $outcome = $checker->check([
            'url' => 'https://example.com/health',
            'expect_status' => 200
        ]);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('HTTP status code 500', $outcome->message);
    }

    public function testCheckBodyMismatch(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('service error');

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')->willReturn($mockResponse);

        $checker = new HttpChecker($mockClient);
        $outcome = $checker->check([
            'url' => 'https://example.com/health',
            'expect_body_contains' => 'healthy'
        ]);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('Body does not contain', $outcome->message);
    }

    public function testCheckConnectionFailure(): void
    {
        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')->willThrowException(new \Exception('Connection refused'));

        $checker = new HttpChecker($mockClient);
        $outcome = $checker->check([
            'url' => 'https://example.com/health'
        ]);

        $this->assertFalse($outcome->success);
        $this->assertStringContainsString('Connection failed', $outcome->message);
    }
}
