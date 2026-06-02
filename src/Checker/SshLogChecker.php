<?php

namespace App\Checker;

use App\Service\SshExecutor;

class SshLogChecker implements CheckerInterface
{
    public function __construct(private SshExecutor $sshExecutor) {}

    public function supports(string $type): bool
    {
        return $type === 'ssh_log';
    }

    public function check(array $config): CheckOutcome
    {
        $host = $config['host'] ?? '';
        $user = $config['user'] ?? 'root';
        $file = $config['file'] ?? '';
        $lines = (int) ($config['lines'] ?? 200);
        $grep = $config['grep'] ?? '';
        $port = isset($config['port']) ? (int) $config['port'] : null;

        if (empty($host) || empty($file)) {
            return new CheckOutcome(false, 'Missing host or file path for SSH Log check');
        }

        $startTime = microtime(true);

        if (!empty($grep)) {
            $cmd = sprintf(
                "tail -n %d %s 2>/dev/null | grep -E -i %s",
                $lines,
                escapeshellarg($file),
                escapeshellarg($grep)
            );
        } else {
            $cmd = sprintf("tail -n %d %s 2>/dev/null", $lines, escapeshellarg($file));
        }

        [$success, $output] = $this->sshExecutor->execute($host, $user, $cmd, $port);
        $responseTime = round(microtime(true) - $startTime, 3);

        if (!$success) {
            return new CheckOutcome(false, sprintf('SSH connection or command failed: %s', $output), $responseTime);
        }

        $output = trim($output);

        if (empty($output)) {
            return new CheckOutcome(true, 'OK (No matching log lines)', $responseTime, ['matched_lines' => []]);
        }

        $matchedLines = explode("\n", $output);
        $count = count($matchedLines);

        // If grep was specified, finding any matching lines indicates a failure (errors detected)
        if (!empty($grep)) {
            return new CheckOutcome(
                false,
                sprintf('%d matching error log lines found', $count),
                $responseTime,
                [
                    'count' => $count,
                    'matched_lines' => array_slice($matchedLines, -20), // return last 20 matches as context
                ]
            );
        }

        return new CheckOutcome(
            true,
            sprintf('OK (%d log lines retrieved)', $count),
            $responseTime,
            [
                'count' => $count,
                'matched_lines' => array_slice($matchedLines, -20),
            ]
        );
    }
}
