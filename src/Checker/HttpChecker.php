<?php

namespace App\Checker;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpChecker implements CheckerInterface
{
    public function __construct(private HttpClientInterface $client) {}

    public function supports(string $type): bool
    {
        return in_array($type, ['http', 'https'], true);
    }

    public function check(array $config): CheckOutcome
    {
        $url = $config['url'] ?? '';
        if (empty($url)) {
            return new CheckOutcome(false, 'Missing target URL');
        }

        $startTime = microtime(true);

        try {
            $response = $this->client->request('GET', $url, [
                'timeout'       => $config['timeout'] ?? 10,
                'max_redirects' => $config['max_redirects'] ?? 5,
            ]);

            // Force request execution by retrieving status code
            $statusCode   = $response->getStatusCode();
            $body         = $response->getContent(false);
            $responseTime = round(microtime(true) - $startTime, 3);

            // Verify expected HTTP status code (defaults to 200)
            $expectedStatus = $config['expect_status'] ?? 200;
            if ($statusCode !== $expectedStatus) {
                return new CheckOutcome(
                    false,
                    sprintf('HTTP status code %d (expected %d)', $statusCode, $expectedStatus),
                    $responseTime,
                    ['status_code' => $statusCode, 'body_preview' => mb_substr($body, 0, 500)]
                );
            }

            // Verify body contents if requested
            $needle = $config['expect_body_contains'] ?? '';
            if ($needle !== '' && !str_contains($body, $needle)) {
                return new CheckOutcome(
                    false,
                    sprintf('Body does not contain: "%s"', $needle),
                    $responseTime,
                    ['status_code' => $statusCode, 'body_preview' => mb_substr($body, 0, 500)]
                );
            }

            return new CheckOutcome(true, 'OK', $responseTime, ['status_code' => $statusCode]);

        } catch (\Throwable $e) {
            $responseTime = round(microtime(true) - $startTime, 3);
            return new CheckOutcome(
                false,
                sprintf('Connection failed: %s', $e->getMessage()),
                $responseTime
            );
        }
    }
}
