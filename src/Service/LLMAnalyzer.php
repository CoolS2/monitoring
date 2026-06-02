<?php

namespace App\Service;

use App\Entity\CheckError;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LLMAnalyzer
{
    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $llmLogger, // Inject custom llm log channel
        #[Autowire(env: 'LLM_ENDPOINT')]
        private string $endpoint,
        #[Autowire(env: 'LLM_MODEL')]
        private string $model
    ) {}

    /**
     * Analyzes a failure and returns a structured array with the diagnosis.
     */
    public function analyze(string $checkKey, string $type, string $message, ?string $details = null): array
    {
        $url = rtrim($this->endpoint, '/') . '/chat/completions';
        
        $prompt = sprintf(
            "Check Key: %s\nType: %s\nError Message: %s\nContext/Logs:\n%s",
            $checkKey,
            $type,
            $message,
            $details ?: 'None'
        );

        $systemMessage = "You are a senior site reliability engineer (SRE) and system administrator monitoring a local home server. "
            . "Analyze the provided monitoring data, detect anomalies, identify probable root causes, assign severity, and list actionable recommendations.\n"
            . "You MUST respond with a single JSON object. Do not include markdown code block formatting or extra text. "
            . "The JSON structure MUST contain exactly these keys:\n"
            . "{\n"
            . "  \"summary\": \"Brief summary of the issue\",\n"
            . "  \"probable_cause\": \"Explanation of the probable root cause\",\n"
            . "  \"severity\": \"LOW\"|\"MEDIUM\"|\"HIGH\"|\"CRITICAL\",\n"
            . "  \"recommendations\": [\"action 1\", \"action 2\"]\n"
            . "}";

        $this->llmLogger->info('Sending analysis request to LLM.', [
            'model' => $this->model,
            'check' => $checkKey,
            'prompt' => $prompt
        ]);

        try {
            $response = $this->client->request('POST', $url, [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemMessage],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'] // Force JSON mode if supported
                ],
                'timeout' => 30 // Wait up to 30s for local LLMs
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \Exception(sprintf('LLM API returned status code %d', $statusCode));
            }

            $responseData = $response->toArray();
            $rawContent = $responseData['choices'][0]['message']['content'] ?? '';
            
            $this->llmLogger->info('Received raw response from LLM.', [
                'raw_content' => $rawContent
            ]);

            $parsedData = $this->parseJson($rawContent);

            if ($parsedData === null) {
                throw new \Exception('Failed to parse JSON response from LLM');
            }

            // Standardize severity keys
            $severity = strtoupper($parsedData['severity'] ?? 'MEDIUM');
            if (!in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true)) {
                $severity = 'MEDIUM';
            }

            return [
                'success' => true,
                'prompt' => $prompt,
                'raw_response' => $rawContent,
                'summary' => $parsedData['summary'] ?? $message,
                'probable_cause' => $parsedData['probable_cause'] ?? 'Unknown cause',
                'severity' => $severity,
                'recommendations' => $parsedData['recommendations'] ?? []
            ];

        } catch (\Throwable $e) {
            $this->llmLogger->error('LLM analysis request failed.', [
                'error' => $e->getMessage()
            ]);

            // Return fallback diagnostics so the monitor loop doesn't fail
            return [
                'success' => false,
                'prompt' => $prompt,
                'raw_response' => $e->getMessage(),
                'summary' => 'Monitoring alert: ' . $message,
                'probable_cause' => 'LLM analyzer failed to execute diagnostics: ' . $e->getMessage(),
                'severity' => 'MEDIUM',
                'recommendations' => ['Investigate the server logs manually to identify the problem.']
            ];
        }
    }

    /**
     * Parses a potentially markdown-wrapped JSON string from LLM.
     */
    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?/i', '', $content);
            $content = preg_replace('/```$/', '', $content);
            $content = trim($content);
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}
