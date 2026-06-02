<?php

namespace App\Checker;

class SslChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return $type === 'ssl';
    }

    public function check(array $config): CheckOutcome
    {
        $host = $config['host'] ?? '';
        if (empty($host)) {
            $url = $config['url'] ?? '';
            if (!empty($url)) {
                $host = parse_url($url, PHP_URL_HOST);
            }
        }

        if (empty($host)) {
            return new CheckOutcome(false, 'Missing host or URL for SSL check');
        }

        $port = $config['port'] ?? 443;
        $timeout = $config['timeout'] ?? 10;
        $warningDays = $config['warning_days'] ?? 14;

        $startTime = microtime(true);

        try {
            $streamContext = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);

            $client = @stream_socket_client(
                "ssl://" . $host . ":" . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $streamContext
            );

            if (!$client) {
                $responseTime = round(microtime(true) - $startTime, 3);
                return new CheckOutcome(false, sprintf("SSL connection failed: %s (%d)", $errstr, $errno), $responseTime);
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (!$cert) {
                $responseTime = round(microtime(true) - $startTime, 3);
                return new CheckOutcome(false, "Failed to retrieve SSL certificate from connection", $responseTime);
            }

            $certInfo = openssl_x509_parse($cert);
            if (!$certInfo || !isset($certInfo['validTo_time_t'])) {
                $responseTime = round(microtime(true) - $startTime, 3);
                return new CheckOutcome(false, "Failed to parse SSL certificate", $responseTime);
            }

            $expirationTime = $certInfo['validTo_time_t'];
            $daysLeft = (int) ceil(($expirationTime - time()) / 86400);
            $responseTime = round(microtime(true) - $startTime, 3);

            $extra = [
                'days_left' => $daysLeft,
                'expiration_date' => date('Y-m-d H:i:s', $expirationTime),
                'issuer' => $certInfo['issuer']['O'] ?? ($certInfo['issuer']['CN'] ?? 'Unknown'),
            ];

            if ($daysLeft <= 0) {
                return new CheckOutcome(false, "SSL certificate has expired", $responseTime, $extra);
            }

            if ($daysLeft <= $warningDays) {
                return new CheckOutcome(
                    false,
                    sprintf("SSL certificate expires in %d days (threshold is %d)", $daysLeft, $warningDays),
                    $responseTime,
                    $extra
                );
            }

            return new CheckOutcome(true, sprintf("OK (%d days remaining)", $daysLeft), $responseTime, $extra);

        } catch (\Throwable $e) {
            $responseTime = round(microtime(true) - $startTime, 3);
            return new CheckOutcome(false, sprintf("SSL check error: %s", $e->getMessage()), $responseTime);
        }
    }
}
