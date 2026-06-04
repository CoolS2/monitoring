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
        $targets = $this->resolveTargets($config);

        if (empty($targets)) {
            return new CheckOutcome(false, 'Missing host or file path for SSH Log check');
        }

        $lines  = (int) ($config['lines'] ?? 200);
        $grep   = $config['grep'] ?? '';

        $startTime = microtime(true);

        $totalMatched  = 0;
        $allLines      = [];
        $failedTargets = [];

        foreach ($targets as $target) {
            $host = $target['host'] ?? '';
            $user = $target['user'] ?? ($config['user'] ?? 'root');
            $file = $target['file'] ?? '';
            $port = isset($target['port']) ? (int) $target['port'] : null;

            if (empty($host) || empty($file)) {
                $failedTargets[] = sprintf('invalid target (host=%s, file=%s)', $host, $file);
                continue;
            }

            $cmd = $this->buildCommand($file, $lines, $grep);

            [$success, $output] = $this->sshExecutor->execute($host, $user, $cmd, $port);

            if (!$success) {
                $failedTargets[] = sprintf('%s:%s — SSH error: %s', $host, $file, $output);
                continue;
            }

            $output = trim($output);
            if (!empty($output)) {
                $matched       = explode("\n", $output);
                $totalMatched += count($matched);
                // Tag each line with its source for context
                foreach ($matched as $line) {
                    $allLines[] = sprintf('[%s] %s', $host, $line);
                }
            }
        }

        $responseTime = round(microtime(true) - $startTime, 3);

        // SSH connection failures are always reported as errors
        if (!empty($failedTargets) && $totalMatched === 0 && empty($allLines)) {
            return new CheckOutcome(
                false,
                sprintf('SSH connection or command failed for %d target(s): %s', count($failedTargets), implode('; ', $failedTargets)),
                $responseTime
            );
        }

        // If grep was specified, any matched lines indicate a failure (errors detected)
        if (!empty($grep)) {
            if ($totalMatched > 0) {
                $messages = [];
                if (!empty($failedTargets)) {
                    $messages[] = sprintf('%d target(s) failed SSH', count($failedTargets));
                }
                $messages[] = sprintf('%d matching error log line(s) found', $totalMatched);

                return new CheckOutcome(
                    false,
                    implode('; ', $messages),
                    $responseTime,
                    [
                        'count'         => $totalMatched,
                        'matched_lines' => array_slice($allLines, -20), // last 20 matches as context
                        'failed_targets' => $failedTargets,
                    ]
                );
            }

            // Grep specified but nothing found — all clear
            $okMsg = sprintf('OK (No matching log lines across %d target(s))', count($targets));
            if (!empty($failedTargets)) {
                return new CheckOutcome(
                    false,
                    sprintf('%s; but %d target(s) failed SSH', $okMsg, count($failedTargets)),
                    $responseTime,
                    ['failed_targets' => $failedTargets, 'matched_lines' => []]
                );
            }

            return new CheckOutcome(true, $okMsg, $responseTime, ['matched_lines' => []]);
        }

        // No grep: just return retrieved lines
        return new CheckOutcome(
            true,
            sprintf('OK (%d log line(s) retrieved across %d target(s))', $totalMatched, count($targets)),
            $responseTime,
            [
                'count'         => $totalMatched,
                'matched_lines' => array_slice($allLines, -20),
                'failed_targets' => $failedTargets,
            ]
        );
    }

    /**
     * Builds the remote shell command to tail and optionally grep the log file.
     */
    private function buildCommand(string $file, int $lines, string $grep): string
    {
        if (!empty($grep)) {
            return sprintf(
                'tail -n %d %s 2>/dev/null | grep -E -i %s',
                $lines,
                escapeshellarg($file),
                escapeshellarg($grep)
            );
        }

        return sprintf('tail -n %d %s 2>/dev/null', $lines, escapeshellarg($file));
    }

    /**
     * Resolves the list of targets from either the new `targets` array format
     * or the legacy single host/file format (backward compatible).
     *
     * New format:
     *   targets:
     *     - { host: 1.2.3.4, user: root, file: /var/log/nginx/error.log }
     *     - { host: 5.6.7.8, user: root, file: /var/log/nginx/error.log, port: 2222 }
     *
     * Legacy format (still supported):
     *   host: 1.2.3.4
     *   user: root
     *   file: /var/log/nginx/error.log
     *
     * @return array<int, array{host: string, user: string, file: string, port?: int}>
     */
    private function resolveTargets(array $config): array
    {
        if (!empty($config['targets']) && is_array($config['targets'])) {
            return $config['targets'];
        }

        // Legacy single-target format
        $host = $config['host'] ?? '';
        $file = $config['file'] ?? '';

        if (empty($host) || empty($file)) {
            return [];
        }

        return [
            [
                'host' => $host,
                'user' => $config['user'] ?? 'root',
                'file' => $file,
                'port' => isset($config['port']) ? (int) $config['port'] : null,
            ],
        ];
    }
}
