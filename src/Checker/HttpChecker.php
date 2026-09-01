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
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            return new CheckOutcome(false, 'Missing target URL');
        }

        $timeout = (float) ($config['timeout'] ?? 10);

        $startTime = microtime(true);

        try {
            $response = $this->client->request('GET', $url, [
                // `timeout` is the idle timeout between chunks; `max_duration`
                // bounds the whole request so a trickling server cannot hang
                // the cron run indefinitely.
                'timeout'       => $timeout,
                'max_duration'  => $timeout * 3,
                'max_redirects' => (int) ($config['max_redirects'] ?? 5),
                'headers'       => ['User-Agent' => 'monitoring-bot/1.0'],
            ]);

            // Force request execution by retrieving status code
            $statusCode   = $response->getStatusCode();
            $body         = $response->getContent(false);
            $responseTime = round(microtime(true) - $startTime, 3);

            // Verify expected HTTP status code (defaults to 200).
            // Cast because YAML happily yields the string "200".
            $expectedStatus = (int) ($config['expect_status'] ?? 200);
            if ($statusCode !== $expectedStatus) {
                return new CheckOutcome(
                    false,
                    sprintf('HTTP status code %d (expected %d)', $statusCode, $expectedStatus),
                    $responseTime,
                    ['status_code' => $statusCode, 'body_preview' => mb_substr($body, 0, 500)]
                );
            }

            // Verify body contents if requested
            $needle = (string) ($config['expect_body_contains'] ?? '');
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
