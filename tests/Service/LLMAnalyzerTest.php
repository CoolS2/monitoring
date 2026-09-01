<?php

namespace App\Tests\Service;

use App\Service\LLMAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class LLMAnalyzerTest extends TestCase
{
    public function testAnalyzeSuccess(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'System is overloaded',
                            'probable_cause' => 'CPU spikes',
                            'severity' => 'HIGH',
                            'recommendations' => ['Scale resources']
                        ])
                    ]
                ]
            ]
        ]);

        $mockClient = $this->createMock(HttpClientInterface::class);
        // Verify that timeout is passed to the request options
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://localhost:11434/v1/chat/completions',
                $this->callback(function ($options) {
                    return isset($options['timeout']) && $options['timeout'] === 45;
                })
            )
            ->willReturn($mockResponse);

        $mockLogger = $this->createMock(LoggerInterface::class);

        $analyzer = new LLMAnalyzer(
            $mockClient,
            $mockLogger,
            'http://localhost:11434/v1',
            'llama3',
            45
        );

        $result = $analyzer->analyze('test_key', 'http', 'Connection timeout');

        $this->assertTrue($result['success']);
        $this->assertEquals('System is overloaded', $result['summary']);
        $this->assertEquals('CPU spikes', $result['probable_cause']);
        $this->assertEquals('HIGH', $result['severity']);
        $this->assertEquals(['Scale resources'], $result['recommendations']);
    }

    public function testAnalyzeFailure(): void
    {
        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')->willThrowException(new \Exception('Connection timeout to local LLM'));

        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('error')
            ->with($this->equalTo('LLM analysis request failed.'), $this->anything());

        $analyzer = new LLMAnalyzer(
            $mockClient,
            $mockLogger,
            'http://localhost:11434/v1',
            'llama3',
            45
        );

        $result = $analyzer->analyze('test_key', 'http', 'Connection timeout');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection timeout to local LLM', $result['probable_cause']);
        $this->assertEquals('MEDIUM', $result['severity']);
        $this->assertCount(1, $result['recommendations']);
    }

    /**
     * Local models are inconsistent: they wrap JSON in markdown fences, prepend
     * a sentence, return recommendations as one string, or lower-case severity.
     * All of it has to normalise into the same structure.
     */
    #[DataProvider('sloppyResponseProvider')]
    public function testSloppyModelResponsesAreNormalized(string $content, array $expected): void
    {
        $analyzer = $this->analyzerReturning($content);

        $result = $analyzer->analyze('server_health', 'script', 'STATUS WARN');

        $this->assertTrue($result['success'], 'A parseable response must count as success');
        $this->assertSame($expected['severity'], $result['severity']);
        $this->assertSame($expected['recommendations'], $result['recommendations']);
        $this->assertSame($expected['summary'], $result['summary']);
    }

    public static function sloppyResponseProvider(): iterable
    {
        yield 'markdown fenced' => [
            "```json\n{\"summary\":\"Disk filling up\",\"probable_cause\":\"logs\",\"severity\":\"high\",\"recommendations\":[\"Rotate logs\"]}\n```",
            [
                'severity'        => 'HIGH',
                'recommendations' => ['Rotate logs'],
                'summary'         => 'Disk filling up',
            ],
        ];

        yield 'prose before the object' => [
            "Here is the analysis:\n{\"summary\":\"Disk filling up\",\"probable_cause\":\"logs\",\"severity\":\"CRITICAL\",\"recommendations\":[\"Rotate logs\"]}",
            [
                'severity'        => 'CRITICAL',
                'recommendations' => ['Rotate logs'],
                'summary'         => 'Disk filling up',
            ],
        ];

        yield 'recommendations as a newline separated string' => [
            '{"summary":"Disk filling up","probable_cause":"logs","severity":"MEDIUM","recommendations":"- Rotate logs\n- Add monitoring"}',
            [
                'severity'        => 'MEDIUM',
                'recommendations' => ['Rotate logs', 'Add monitoring'],
                'summary'         => 'Disk filling up',
            ],
        ];

        yield 'unknown severity falls back to MEDIUM' => [
            '{"summary":"Disk filling up","probable_cause":"logs","severity":"SEVERE","recommendations":[]}',
            [
                'severity'        => 'MEDIUM',
                'recommendations' => [],
                'summary'         => 'Disk filling up',
            ],
        ];
    }

    /**
     * A log dump must not be sent to the model verbatim: the prompt is clamped,
     * keeping the head (which carries the STATUS banner) and the freshest tail.
     */
    public function testLongContextIsClamped(): void
    {
        $sentPrompt = null;

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'choices' => [['message' => ['content' => '{"summary":"s","probable_cause":"c","severity":"LOW","recommendations":[]}']]],
        ]);

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$sentPrompt) {
                $sentPrompt = $options['json']['messages'][1]['content'];
                return $mockResponse;
            });

        $analyzer = new LLMAnalyzer(
            $mockClient,
            $this->createMock(LoggerInterface::class),
            'http://localhost:11434/v1',
            'llama3',
            45,
            'English',
            1000
        );

        $details = 'HEAD-MARKER' . str_repeat('x', 50000) . 'TAIL-MARKER';
        $analyzer->analyze('server_health', 'script', 'STATUS WARN', $details);

        $this->assertLessThan(2000, mb_strlen($sentPrompt));
        $this->assertStringContainsString('HEAD-MARKER', $sentPrompt);
        $this->assertStringContainsString('TAIL-MARKER', $sentPrompt);
        $this->assertStringContainsString('[context truncated]', $sentPrompt);
    }

    /**
     * Runtimes that do not understand `response_format` answer with a 4xx;
     * the request is retried once in plain mode rather than losing the analysis.
     */
    public function testJsonModeRejectionIsRetriedWithoutResponseFormat(): void
    {
        $rejecting = $this->createMock(ResponseInterface::class);
        $rejecting->method('getStatusCode')->willReturn(400);

        $accepting = $this->createMock(ResponseInterface::class);
        $accepting->method('getStatusCode')->willReturn(200);
        $accepting->method('toArray')->willReturn([
            'choices' => [['message' => ['content' => '{"summary":"ok","probable_cause":"c","severity":"LOW","recommendations":[]}']]],
        ]);

        $payloads = [];
        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($rejecting, $accepting, &$payloads) {
                $payloads[] = $options['json'];
                return count($payloads) === 1 ? $rejecting : $accepting;
            });

        $analyzer = new LLMAnalyzer(
            $mockClient,
            $this->createMock(LoggerInterface::class),
            'http://localhost:11434/v1',
            'llama3',
            45
        );

        $result = $analyzer->analyze('server_health', 'script', 'STATUS WARN');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('response_format', $payloads[0]);
        $this->assertArrayNotHasKey('response_format', $payloads[1]);
    }

    private function analyzerReturning(string $content): LLMAnalyzer
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'choices' => [['message' => ['content' => $content]]],
        ]);

        $mockClient = $this->createMock(HttpClientInterface::class);
        $mockClient->method('request')->willReturn($mockResponse);

        return new LLMAnalyzer(
            $mockClient,
            $this->createMock(LoggerInterface::class),
            'http://localhost:11434/v1',
            'llama3',
            45
        );
    }
}
