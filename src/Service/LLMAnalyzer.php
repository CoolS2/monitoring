<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LLMAnalyzer
{
    /** Severities the analyzer is allowed to return. */
    private const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** Recommendations beyond this count are dropped to keep alerts readable. */
    private const MAX_RECOMMENDATIONS = 6;

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $llmLogger, // Inject custom llm log channel
        #[Autowire(env: 'LLM_ENDPOINT')]
        private string $endpoint,
        #[Autowire(env: 'LLM_MODEL')]
        private string $model,
        #[Autowire(env: 'int:LLM_TIMEOUT')]
        private int $timeout,
        #[Autowire(env: 'LLM_LANGUAGE')]
        private string $language = 'English',
        #[Autowire(env: 'int:LLM_MAX_CONTEXT_CHARS')]
        private int $maxContextChars = 6000,
    ) {}

    /**
     * Analyzes a failure and returns a structured array with the diagnosis.
     *
     * Never throws: when the LLM is unreachable or answers with garbage a
     * fallback diagnosis is returned so alerting keeps working.
     *
     * When no endpoint is configured the analyzer is considered switched off: the
     * call returns quietly without `summary`/`severity`, so `renderAnalysis()`
     * appends no diagnostics block at all.
     *
     * @return array{success: bool, prompt: string, raw_response: string, summary?: string,
     *               probable_cause: string, severity?: string, recommendations: list<string>}
     */
    public function analyze(string $checkKey, string $type, string $message, ?string $details = null): array
    {
        if (trim($this->endpoint) === '') {
            return [
                'success'         => false,
                'prompt'          => '',
                'raw_response'    => '',
                'probable_cause'  => '',
                'recommendations' => [],
            ];
        }

        $url    = rtrim($this->endpoint, '/') . '/chat/completions';
        $prompt = $this->buildPrompt($checkKey, $type, $message, $details);

        $this->llmLogger->info('Sending analysis request to LLM.', [
            'model'   => $this->model,
            'check'   => $checkKey,
            'prompt'  => $prompt,
            'timeout' => $this->timeout,
        ]);

        try {
            $rawContent = $this->requestCompletion($url, $prompt);

            $this->llmLogger->info('Received raw response from LLM.', [
                'raw_content' => $rawContent,
            ]);

            $parsedData = $this->parseJson($rawContent);

            if ($parsedData === null) {
                throw new \RuntimeException('Failed to parse JSON response from LLM');
            }

            return [
                'success'         => true,
                'prompt'          => $prompt,
                'raw_response'    => $rawContent,
                'summary'         => $this->stringify($parsedData['summary'] ?? null) ?: $message,
                'probable_cause'  => $this->stringify($parsedData['probable_cause'] ?? null) ?: 'Unknown cause',
                'severity'        => $this->normalizeSeverity($parsedData['severity'] ?? null),
                'recommendations' => $this->normalizeRecommendations($parsedData['recommendations'] ?? null),
            ];
        } catch (\Throwable $e) {
            $this->llmLogger->error('LLM analysis request failed.', [
                'error' => $e->getMessage(),
            ]);

            // Return fallback diagnostics so the monitor loop doesn't fail
            return [
                'success'         => false,
                'prompt'          => $prompt,
                'raw_response'    => $e->getMessage(),
                'summary'         => 'Monitoring alert: ' . $message,
                'probable_cause'  => 'LLM analyzer failed to execute diagnostics: ' . $e->getMessage(),
                'severity'        => 'MEDIUM',
                'recommendations' => ['Investigate the server logs manually to identify the problem.'],
            ];
        }
    }

    /**
     * Performs the chat completion request.
     *
     * Not every OpenAI-compatible runtime understands `response_format`; when the
     * server rejects the payload the call is retried once without it so an older
     * LocalAI/llama.cpp build still yields a diagnosis.
     */
    private function requestCompletion(string $url, string $prompt): string
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $this->buildSystemMessage()],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
        ];

        $withJsonMode = $payload + ['response_format' => ['type' => 'json_object']];

        try {
            return $this->send($url, $withJsonMode);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'status code 4')) {
                throw $e;
            }

            $this->llmLogger->warning('LLM rejected JSON mode, retrying without response_format.', [
                'error' => $e->getMessage(),
            ]);

            return $this->send($url, $payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $url, array $payload): string
    {
        $response = $this->client->request('POST', $url, [
            'json'    => $payload,
            'timeout' => $this->timeout,
            // Local models can stall between tokens; cap the whole exchange too.
            'max_duration' => $this->timeout * 3,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('LLM API returned status code %d', $statusCode));
        }

        $responseData = $response->toArray();

        return (string) ($responseData['choices'][0]['message']['content'] ?? '');
    }

    private function buildSystemMessage(): string
    {
        return "You are a senior site reliability engineer (SRE) and system administrator monitoring a self-hosted server. "
            . "Analyze the provided monitoring data, detect anomalies, identify probable root causes, assign severity, and list actionable recommendations.\n"
            . sprintf("Write every human readable value in %s. Be concise: the result is delivered as a short Telegram message. ", $this->language)
            . "Keep \"summary\" to one or two sentences, \"probable_cause\" to two sentences at most, and give at most "
            . self::MAX_RECOMMENDATIONS . " short recommendations.\n"
            . "You MUST respond with a single JSON object. Do not include markdown code block formatting or extra text. "
            . "The JSON structure MUST contain exactly these keys:\n"
            . "{\n"
            . "  \"summary\": \"Brief summary of the issue\",\n"
            . "  \"probable_cause\": \"Explanation of the probable root cause\",\n"
            . "  \"severity\": \"LOW\"|\"MEDIUM\"|\"HIGH\"|\"CRITICAL\",\n"
            . "  \"recommendations\": [\"action 1\", \"action 2\"]\n"
            . "}";
    }

    private function buildPrompt(string $checkKey, string $type, string $message, ?string $details): string
    {
        return sprintf(
            "Check Key: %s\nType: %s\nError Message: %s\nContext/Logs:\n%s",
            $checkKey,
            $type,
            $message,
            $this->clampContext($details)
        );
    }

    /**
     * Keeps log context within a size a local model can actually digest.
     * The head carries the status banner and the tail the freshest lines, so
     * the middle is what gets dropped.
     */
    private function clampContext(?string $details): string
    {
        $details = $details !== null ? trim($details) : '';
        if ($details === '') {
            return 'None';
        }

        $limit = max(500, $this->maxContextChars);
        if (mb_strlen($details) <= $limit) {
            return $details;
        }

        $headLength = (int) floor($limit * 0.6);
        $tailLength = $limit - $headLength;

        return mb_substr($details, 0, $headLength)
            . "\n… [context truncated] …\n"
            . mb_substr($details, -$tailLength);
    }

    /**
     * Parses a potentially markdown-wrapped JSON string from LLM.
     */
    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```(?:json)?/i', '', $content);
            $content = (string) preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Some models prepend a sentence before the object; salvage the JSON body.
        $start = strpos($content, '{');
        $end   = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function normalizeSeverity(mixed $value): string
    {
        $severity = strtoupper(trim($this->stringify($value)));

        return in_array($severity, self::SEVERITIES, true) ? $severity : 'MEDIUM';
    }

    /**
     * Models return recommendations as a list, a single string, or a list of
     * objects. All three shapes are flattened into a list of strings.
     *
     * @return list<string>
     */
    private function normalizeRecommendations(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = preg_split('/\R+/', $this->stringify($value)) ?: [];
        }

        $recommendations = [];
        foreach ($value as $item) {
            $text = trim($this->stringify($item));
            $text = ltrim($text, "-*• \t");
            if ($text !== '') {
                $recommendations[] = $text;
            }
        }

        return array_slice($recommendations, 0, self::MAX_RECOMMENDATIONS);
    }

    /**
     * Coerces whatever the model returned into a plain string.
     */
    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return implode(' ', array_map(fn ($item): string => $this->stringify($item), $value));
        }

        return '';
    }
}
