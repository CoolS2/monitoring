<?php

namespace App\Tests\Alert;

use App\Alert\TelegramNotifier;
use App\Config\TelegramConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class TelegramNotifierTest extends TestCase
{
    public function testSendSuccess(): void
    {
        $config = new TelegramConfig('test_token', '123456789');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.telegram.org/bottest_token/sendMessage',
                $this->callback(function ($options) {
                    return $options['json']['chat_id'] === '123456789'
                        && $options['json']['text'] === 'Hello world';
                })
            )
            ->willReturn($mockResponse);

        $mockLogger = $this->createMock(LoggerInterface::class);
        $infoLogs = [];
        $mockLogger->method('info')
            ->willReturnCallback(function ($message, $context = []) use (&$infoLogs) {
                $infoLogs[] = $message;
            });

        $notifier = new TelegramNotifier($mockClient, $config, $mockLogger);
        $notifier->send('Hello world');

        $this->assertCount(2, $infoLogs);
        $this->assertEquals('Sending Telegram notification request.', $infoLogs[0]);
        $this->assertEquals('Telegram notification sent successfully.', $infoLogs[1]);
    }

    public function testSendFailureStatusCode(): void
    {
        $config = new TelegramConfig('test_token', '123456789');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(400);
        $mockResponse->method('getContent')->willReturn('{"ok":false,"description":"Bad Request"}');

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('info')
            ->with('Sending Telegram notification request.', $this->anything());

        $mockLogger->expects($this->once())
            ->method('error')
            ->with(
                'Telegram notification delivery failed.',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'status code 400')
                        && str_contains($context['error'], 'Bad Request');
                })
            );

        $notifier = new TelegramNotifier($mockClient, $config, $mockLogger);
        // Should catch exception and log it instead of throwing
        $notifier->send('Hello world');
    }

    public function testSendException(): void
    {
        $config = new TelegramConfig('test_token', '123456789');

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Network connection error'));

        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('info')
            ->with('Sending Telegram notification request.', $this->anything());

        $mockLogger->expects($this->once())
            ->method('error')
            ->with(
                'Telegram notification delivery failed.',
                $this->callback(function ($context) {
                    return $context['error'] === 'Network connection error';
                })
            );

        $notifier = new TelegramNotifier($mockClient, $config, $mockLogger);
        // Should catch and log network exceptions gracefully
        $notifier->send('Hello world');
    }

    public function testSendTruncatesLongMessages(): void
    {
        $config = new TelegramConfig('test_token', '123456789');

        $longMessage = str_repeat('A', 5000); // exceeds Telegram's 4096-char limit

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $sentText = null;
        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$sentText) {
                $sentText = $options['json']['text'];
                return $mockResponse;
            });

        $mockLogger = $this->createMock(LoggerInterface::class);

        $notifier = new TelegramNotifier($mockClient, $config, $mockLogger);
        $notifier->send($longMessage);

        $this->assertNotNull($sentText);
        $this->assertLessThanOrEqual(4096, mb_strlen($sentText));
        $this->assertStringContainsString('[...truncated', $sentText);
    }

    /**
     * Blindly cutting HTML can leave an unclosed tag behind, which Telegram
     * rejects with a parse error. The truncated message must stay well-formed.
     */
    public function testTruncationClosesOpenTags(): void
    {
        $sentText = $this->captureSent('<b>' . str_repeat('A', 5000) . '</b>');

        $this->assertLessThanOrEqual(4096, mb_strlen($sentText));
        $this->assertStringContainsString('[...truncated', $sentText);
        $this->assertSame(
            substr_count($sentText, '<b>'),
            substr_count($sentText, '</b>'),
            'Every opened <b> must be closed after truncation'
        );
    }

    public function testTruncationDoesNotLeaveHalfWrittenTag(): void
    {
        // Engineered so the cut lands in the middle of the trailing <code> tag
        $text = str_repeat('B', 4020) . '<code>value</code>';

        $sentText = $this->captureSent($text);

        $this->assertLessThanOrEqual(4096, mb_strlen($sentText));
        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z]*$/', $sentText);
        $this->assertSame(
            substr_count($sentText, '<code>'),
            substr_count($sentText, '</code>'),
            'A partially written <code> tag must not survive truncation'
        );
    }

    /**
     * Telegram's HTML mode only requires &, < and > to be escaped. Escaping
     * quotes into numeric entities makes them render literally in some clients.
     */
    public function testAlertEscapesMarkupButNotQuotes(): void
    {
        $sentText = null;

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$sentText) {
                $sentText = $options['json']['text'];
                return $mockResponse;
            });

        $notifier = new TelegramNotifier(
            $mockClient,
            new TelegramConfig('test_token', '1'),
            $this->createMock(LoggerInterface::class)
        );

        $notifier->sendAlert('site', 'http', 'Body does not contain: "ok" & <b>fails</b>');

        $this->assertStringContainsString('&lt;b&gt;fails&lt;/b&gt;', $sentText);
        $this->assertStringContainsString('&amp;', $sentText);
        $this->assertStringContainsString('"ok"', $sentText);
        $this->assertStringNotContainsString('&quot;', $sentText);
    }

    public function testServerReportRendersStatusesAndAnalysis(): void
    {
        $sentText = null;

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$sentText) {
                $sentText = $options['json']['text'];
                return $mockResponse;
            });

        $notifier = new TelegramNotifier(
            $mockClient,
            new TelegramConfig('test_token', '1'),
            $this->createMock(LoggerInterface::class)
        );

        $notifier->sendServerReport(
            'server_health',
            'WARN',
            ['LOGS' => 'WARN', 'SYSTEM' => 'OK'],
            ['[SYSTEM] Disk usage /: 96% (above 90%)'],
            [
                'summary'         => '1037 PHP warnings in the last hour',
                'probable_cause'  => 'A WordPress plugin casts WP_Post to int',
                'severity'        => 'MEDIUM',
                'recommendations' => ['Patch the plugin', 'Re-check in one hour'],
            ]
        );

        $this->assertStringContainsString('Server Report: server_health', $sentText);
        $this->assertStringContainsString('<code>LOGS</code>', $sentText);
        $this->assertStringContainsString('1037 PHP warnings', $sentText);
        $this->assertStringContainsString('• Patch the plugin', $sentText);
        $this->assertStringContainsString('Detected issues:', $sentText);
        $this->assertStringContainsString('Disk usage /: 96% (above 90%)', $sentText);
    }

    /**
     * Sends the given text through a mocked client and returns what was posted.
     */
    private function captureSent(string $text): string
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $sentText = null;
        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$sentText) {
                $sentText = $options['json']['text'];
                return $mockResponse;
            });

        $notifier = new TelegramNotifier(
            $mockClient,
            new TelegramConfig('test_token', '123456789'),
            $this->createMock(LoggerInterface::class)
        );

        $notifier->send($text);

        $this->assertNotNull($sentText);

        return $sentText;
    }
}
